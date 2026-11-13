<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Shared\Clock\Clock;
use App\Domain\Student\Models\Student;
use App\Domain\Wallet\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FrozenClock;

abstract class TestCase extends BaseTestCase
{
    /** Refuse to touch any database that is not a test database (tests run migrate:fresh). */
    protected function setUpTraits()
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            $this->fail("Tests must run against a *_test database, got [{$database}].");
        }

        return parent::setUpTraits();
    }

    protected function freezeClock(string $utc = '2026-12-01 09:00:00'): FrozenClock
    {
        $clock = new FrozenClock(CarbonImmutable::parse($utc, 'UTC'));
        $this->app->instance(Clock::class, $clock);

        return $clock;
    }

    protected function book(Student $student, int $sessionId, ?string $key = null): TestResponse
    {
        return $this->postJson("/sessions/{$sessionId}/bookings", [], [
            'X-Student-Id' => (string) $student->id,
            'Idempotency-Key' => $key ?? 'key-'.bin2hex(random_bytes(8)),
        ]);
    }

    protected function cancel(Student $student, int $bookingId): TestResponse
    {
        return $this->deleteJson("/bookings/{$bookingId}", [], ['X-Student-Id' => (string) $student->id]);
    }

    protected function balanceOf(Student $student): string
    {
        return Wallet::query()->where('student_id', $student->id)->value('balance');
    }

    /** BR-05 / C6: the cached balance always equals the sum of the append-only ledger. */
    protected function assertLedgerMatchesBalances(): void
    {
        $drift = DB::select(<<<'SQL'
            SELECT w.id, w.balance, COALESCE(SUM(t.amount), 0) AS ledger
            FROM wallets w LEFT JOIN wallet_transactions t ON t.wallet_id = w.id
            GROUP BY w.id, w.balance
            HAVING w.balance <> COALESCE(SUM(t.amount), 0)
            SQL);

        $this->assertSame([], $drift, 'wallet balance drifted from its ledger');
    }
}
