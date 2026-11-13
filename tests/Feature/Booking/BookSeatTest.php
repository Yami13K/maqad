<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Catalog\Enums\SessionStatus;
use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\Center;
use App\Domain\Catalog\Models\CourseSession;
use App\Domain\Student\Models\Student;
use App\Domain\Wallet\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FrozenClock;
use Tests\TestCase;

/** E1 through HTTP on real Postgres: BR-03, BR-04, BR-05, BR-07, BR-09, BR-11. */
final class BookSeatTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = $this->freezeClock('2026-12-01 09:00:00');
        $this->branch = Branch::factory()->for(Center::factory()->state(['commission_rate' => '0.1500']))->create();
    }

    private function makeSession(array $state = []): CourseSession
    {
        return CourseSession::factory()->at($this->branch)
            ->startingAt(CarbonImmutable::parse('2026-12-05 15:00:00', 'UTC'))
            ->create($state);
    }

    #[Test]
    public function a_free_seat_is_confirmed_charged_and_snapshotted(): void
    {
        $session = $this->makeSession(['price' => '42.125']);
        $student = Student::factory()->withBalance('100.000')->create();

        $response = $this->book($student, $session->id);

        $response->assertStatus(201)->assertExactJson([
            'booking_id' => $response->json('booking_id'),
            'status' => 'confirmed',
            'charged_amount' => '42.125',
            'refunded_amount' => '0.000',
            'promoted_booking_id' => null,
            'wallet_balance' => '57.875',
        ]);
        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('booking_id'),
            'price_charged' => '42.125',
            'commission_rate' => '0.1500',
            'commission_amount' => '6.319', // 42.125 x 0.15 = 6.31875, half-up once (C11)
            'confirmed_at' => '2026-12-01 09:00:00+00',
        ]);
        $this->assertDatabaseHas('wallet_transactions', ['booking_id' => $response->json('booking_id'), 'type' => 'charge', 'amount' => '-42.125']);
        $this->assertDatabaseHas('booking_status_transitions', ['booking_id' => $response->json('booking_id'), 'from_status' => null, 'to_status' => 'confirmed', 'actor' => 'student']);
        $this->assertSame(1, CourseSession::query()->whereKey($session->id)->value('confirmed_count'));
        $this->assertLedgerMatchesBalances();
    }

    #[Test]
    public function a_full_session_puts_the_student_on_the_waitlist_without_charging(): void
    {
        $session = $this->makeSession(['capacity' => 1]);
        $this->book(Student::factory()->withBalance('100.000')->create(), $session->id)->assertStatus(201);
        $late = Student::factory()->withBalance('100.000')->create();

        $response = $this->book($late, $session->id);

        $response->assertStatus(202)->assertExactJson([
            'booking_id' => $response->json('booking_id'),
            'status' => 'waitlisted',
            'charged_amount' => '0.000',
            'refunded_amount' => '0.000',
            'promoted_booking_id' => null,
            'wallet_balance' => '100.000',
        ]);
        $this->assertDatabaseHas('bookings', ['id' => $response->json('booking_id'), 'waitlisted_at' => '2026-12-01 09:00:00+00', 'price_charged' => null]);
        $this->assertDatabaseMissing('wallet_transactions', ['booking_id' => $response->json('booking_id')]);
    }

    #[Test]
    public function T3_same_idempotency_key_twice_returns_the_same_response_and_charges_once(): void
    {
        $session = $this->makeSession();
        $student = Student::factory()->withBalance('100.000')->create();

        $first = $this->book($student, $session->id, 'retry-me');
        $second = $this->book($student, $session->id, 'retry-me');

        $first->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
        $second->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, DB::table('bookings')->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'charge')->count());
        $this->assertSame('50.000', $this->balanceOf($student));
    }

    #[Test]
    public function the_same_key_on_a_different_request_is_rejected(): void
    {
        $student = Student::factory()->withBalance('200.000')->create();
        $this->book($student, $this->makeSession()->id, 'k')->assertStatus(201);
        $other = $this->makeSession(['starts_at' => '2026-12-06 15:00:00+00', 'ends_at' => '2026-12-06 16:00:00+00']);

        $this->book($student, $other->id, 'k')->assertStatus(422)->assertExactJson([
            'error' => ['code' => 'idempotency_key_reused', 'message' => 'This Idempotency-Key was already used with a different request.'],
        ]);
        $this->assertSame(1, DB::table('bookings')->count());
    }

    #[Test]
    public function a_failed_attempt_releases_the_key_so_the_client_can_retry(): void
    {
        $session = $this->makeSession();
        $student = Student::factory()->withBalance('10.000')->create();

        $this->book($student, $session->id, 'k')->assertStatus(402)->assertExactJson([
            'error' => ['code' => 'insufficient_balance', 'message' => 'Wallet balance is lower than the session price.'],
        ]);
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertSame(0, DB::table('bookings')->count());

        $this->topUp($student, '40.000');

        $this->book($student, $session->id, 'k')->assertStatus(201)->assertJsonPath('wallet_balance', '0.000');
    }

    #[Test]
    public function a_student_cannot_hold_two_active_bookings_on_one_session(): void
    {
        $session = $this->makeSession();
        $student = Student::factory()->withBalance('200.000')->create();
        $this->book($student, $session->id)->assertStatus(201);

        $this->book($student, $session->id)->assertStatus(409)->assertExactJson([
            'error' => ['code' => 'already_booked', 'message' => 'The student already holds an active booking on this session.'],
        ]);
        $this->assertSame('150.000', $this->balanceOf($student));
    }

    #[Test]
    public function an_overlapping_session_is_rejected(): void
    {
        $student = Student::factory()->withBalance('200.000')->create();
        $this->book($student, $this->makeSession()->id)->assertStatus(201); // 15:00 to 16:00
        $clash = $this->makeSession(['starts_at' => '2026-12-05 15:30:00+00', 'ends_at' => '2026-12-05 16:30:00+00']);

        $this->book($student, $clash->id)->assertStatus(409)->assertExactJson([
            'error' => ['code' => 'time_overlap', 'message' => 'The student already holds a booking that overlaps this session.'],
        ]);
    }

    #[Test]
    public function back_to_back_sessions_do_not_overlap(): void
    {
        $student = Student::factory()->withBalance('200.000')->create();
        $this->book($student, $this->makeSession()->id)->assertStatus(201); // ends 16:00
        $next = $this->makeSession(['starts_at' => '2026-12-05 16:00:00+00', 'ends_at' => '2026-12-05 17:00:00+00']);

        $this->book($student, $next->id)->assertStatus(201);
    }

    #[Test]
    public function a_waitlisted_booking_also_blocks_an_overlapping_one(): void
    {
        $full = $this->makeSession(['capacity' => 1]);
        $this->book(Student::factory()->withBalance('100.000')->create(), $full->id)->assertStatus(201);
        $student = Student::factory()->withBalance('200.000')->create();
        $this->book($student, $full->id)->assertStatus(202);
        $clash = $this->makeSession(['starts_at' => '2026-12-05 15:30:00+00', 'ends_at' => '2026-12-05 16:30:00+00']);

        $this->book($student, $clash->id)->assertStatus(409)->assertJsonPath('error.code', 'time_overlap');
    }

    #[Test]
    public function a_started_or_cancelling_session_cannot_be_booked(): void
    {
        $student = Student::factory()->withBalance('200.000')->create();
        $started = $this->makeSession();
        $cancelling = $this->makeSession(['starts_at' => '2026-12-06 15:00:00+00', 'ends_at' => '2026-12-06 16:00:00+00', 'status' => SessionStatus::Cancelling]);
        $body = ['error' => ['code' => 'session_not_bookable', 'message' => 'The session is not open for booking.']];

        $this->book($student, $cancelling->id)->assertStatus(422)->assertExactJson($body);

        $this->clock->setTo(CarbonImmutable::parse('2026-12-05 15:00:00', 'UTC'));
        $this->book($student, $started->id)->assertStatus(422)->assertExactJson($body);
    }

    #[Test]
    public function the_edge_rejects_unknown_students_missing_keys_and_missing_sessions(): void
    {
        $student = Student::factory()->withBalance('100.000')->create();
        $session = $this->makeSession();

        $this->postJson("/sessions/{$session->id}/bookings", [], ['Idempotency-Key' => 'k'])
            ->assertStatus(401)->assertExactJson(['error' => ['code' => 'unknown_student', 'message' => 'Send a valid X-Student-Id header.']]);
        $this->postJson("/sessions/{$session->id}/bookings", [], ['X-Student-Id' => '999999', 'Idempotency-Key' => 'k'])
            ->assertStatus(401);
        $this->postJson("/sessions/{$session->id}/bookings", [], ['X-Student-Id' => (string) $student->id])
            ->assertStatus(422)->assertExactJson(['error' => ['code' => 'invalid_request', 'message' => 'The Idempotency-Key header is required (1 to 255 characters).']]);
        $this->book($student, 999999)
            ->assertStatus(404)->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Session not found.']]);
    }

    private function topUp(Student $student, string $amount): void
    {
        $wallet = $student->wallet()->firstOrFail();
        $wallet->transactions()->create(['type' => 'topup', 'amount' => $amount]);
        $wallet->update(['balance' => Money::of($wallet->balance)->plus(Money::of($amount))->toDecimal()]);
    }
}
