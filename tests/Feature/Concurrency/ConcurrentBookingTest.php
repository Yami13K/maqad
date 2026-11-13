<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Domain\Catalog\Models\CourseSession;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ParallelServer;
use Tests\TestCase;

/**
 * BR-04, BR-07 and BR-11 under real parallelism: every request is in flight at once against
 * a multi-process server and committed Postgres data (no wrapping test transaction).
 */
final class ConcurrentBookingTest extends TestCase
{
    private const int WORKERS = 50;

    private ParallelServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Without ext-curl Guzzle falls back to a stream handler that sends one request at a
        // time, and these tests would pass without any concurrency at all.
        $this->assertTrue(extension_loaded('curl'), 'ext-curl is required for truly parallel requests');

        // The server's processes must see the rows this test creates, so data is committed,
        // not wrapped in a RefreshDatabase transaction. Rebuild the schema instead.
        $this->artisan('migrate:fresh');

        $db = config('database.connections.pgsql');
        $this->server = ParallelServer::start(self::WORKERS, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        // Later RefreshDatabase tests must rebuild from a clean schema.
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    #[Test]
    public function T1_concurrent_booking_never_exceeds_capacity(): void
    {
        $session = CourseSession::factory()->create(['capacity' => 10, 'price' => '50.000']);
        $students = Student::factory()->count(50)->withBalance('100.000')->create();

        $responses = $this->sendAtOnce($students->map(fn (Student $s): array => [$s->id, $session->id, "t1-{$s->id}"])->all());

        $this->assertSame(['201' => 10, '202' => 40], $this->statusCounts($responses));
        $this->assertSame(10, DB::table('bookings')->where('status', 'confirmed')->count());
        $this->assertSame(40, DB::table('bookings')->where('status', 'waitlisted')->count());
        $this->assertSame(10, CourseSession::query()->whereKey($session->id)->value('confirmed_count'));
        $this->assertSame('-500.000', DB::table('wallet_transactions')->where('type', 'charge')->sum('amount'));
        $this->assertSame(10, DB::table('wallet_transactions')->where('type', 'charge')->count());
        $this->assertLedgerMatchesBalances();
    }

    /** Five rounds: one round can pass by lucky timing even with a broken lock, five in a row cannot. */
    private const int T2_ROUNDS = 5;

    #[Test]
    public function T2_one_student_racing_two_overlapping_sessions_gets_one_booking_and_one_charge(): void
    {
        foreach (range(1, self::T2_ROUNDS) as $round) {
            $day = sprintf('2027-02-%02d', $round);
            $first = CourseSession::factory()->create([
                'price' => '50.000', 'starts_at' => "{$day} 15:00:00+00", 'ends_at' => "{$day} 16:00:00+00",
            ]);
            $second = CourseSession::factory()->create([
                'price' => '50.000', 'starts_at' => "{$day} 15:30:00+00", 'ends_at' => "{$day} 16:30:00+00",
            ]);
            $student = Student::factory()->withBalance('60.000')->create(); // enough for one only

            $requests = [];
            foreach (range(1, 5) as $i) {
                $requests[] = [$student->id, $i % 2 === 0 ? $second->id : $first->id, "t2-{$round}-{$i}"];
            }
            $statuses = $this->statusCounts($this->sendAtOnce($requests));

            $context = "round {$round}: ".json_encode($statuses);
            $this->assertSame([201 => 1, 409 => 4], $statuses, $context); // the 4 losers: already_booked or time_overlap
            $this->assertSame(1, DB::table('bookings')->where('student_id', $student->id)->whereIn('status', ['confirmed', 'waitlisted'])->count(), $context);
            $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'charge')->whereIn('booking_id', DB::table('bookings')->where('student_id', $student->id)->select('id'))->count(), $context);
            $this->assertSame('10.000', $this->balanceOf($student), $context);
        }

        $this->assertLedgerMatchesBalances();
    }

    #[Test]
    public function T3_same_idempotency_key_sent_in_parallel_books_and_charges_once(): void
    {
        $session = CourseSession::factory()->create(['price' => '50.000']);
        $student = Student::factory()->withBalance('100.000')->create();

        $responses = $this->sendAtOnce(array_fill(0, 5, [$student->id, $session->id, 'same-key']));

        $this->assertSame(['201' => 5], $this->statusCounts($responses));
        $this->assertCount(1, array_unique(array_map(fn (Response $r): string => $r->body(), $responses)));
        $this->assertSame(4, count(array_filter($responses, fn (Response $r): bool => $r->header('Idempotent-Replayed') === 'true')));
        $this->assertSame(1, DB::table('bookings')->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'charge')->count());
        $this->assertSame('50.000', $this->balanceOf($student));
    }

    /**
     * Fires every request concurrently (curl multi) and waits for all of them.
     *
     * @param  list<array{int, int, string}>  $requests  [student id, session id, idempotency key]
     * @return list<Response>
     */
    private function sendAtOnce(array $requests): array
    {
        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (array $r) => $pool->baseUrl($this->server->baseUrl)
                ->acceptJson()
                ->timeout(30)
                ->withHeaders(['X-Student-Id' => (string) $r[0], 'Idempotency-Key' => $r[2]])
                ->post("/sessions/{$r[1]}/bookings"),
            $requests,
        ), concurrency: count($requests));

        foreach ($responses as $response) {
            $this->assertInstanceOf(Response::class, $response, 'a request failed at the transport level');
        }

        return array_values($responses);
    }

    /** @param list<Response> $responses */
    private function statusCounts(array $responses): array
    {
        $counts = array_count_values(array_map(fn (Response $r): string => (string) $r->status(), $responses));
        ksort($counts);

        return $counts;
    }
}
