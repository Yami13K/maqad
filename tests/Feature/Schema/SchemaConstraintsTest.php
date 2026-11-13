<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves that each rule from ANALYSIS_REVIEW.md section C is enforced by PostgreSQL
 * itself: every statement here must be rejected even though it bypasses the app.
 */
class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> ids of the fixture rows, substituted into the SQL as {name} */
    private array $ids = [];

    /** @return array<string, array{string, string}> */
    public static function violations(): array
    {
        return [
            'C1 session mixes course of center 1 with branch of center 2' => [
                "INSERT INTO sessions (course_id, branch_id, center_id, starts_at, ends_at, capacity, price)
                 VALUES ({course}, (SELECT id FROM branches WHERE center_id = {center2} LIMIT 1), {center}, now(), now() + interval '1 hour', 5, 10)",
                '23503',
            ],
            'C3 confirmed_count above capacity (BR-04)' => [
                'UPDATE sessions SET confirmed_count = capacity + 1 WHERE id = {session}',
                '23514',
            ],
            'C3 confirmed_count below zero' => [
                'UPDATE sessions SET confirmed_count = -1 WHERE id = {session}',
                '23514',
            ],
            'C4 ledger row update is refused' => [
                'UPDATE wallet_transactions SET amount = 1 WHERE id = {ledger}',
                '23001',
            ],
            'C4 ledger row delete is refused' => [
                'DELETE FROM wallet_transactions WHERE id = {ledger}',
                '23001',
            ],
            'C4 ledger truncate is refused' => [
                'TRUNCATE wallet_transactions CASCADE',
                '23001',
            ],
            'C5 charge with positive amount' => [
                "INSERT INTO wallet_transactions (wallet_id, booking_id, type, amount) VALUES ({wallet}, {booking}, 'charge', 5)",
                '23514',
            ],
            'C5 top-up linked to a booking' => [
                "INSERT INTO wallet_transactions (wallet_id, booking_id, type, amount) VALUES ({wallet}, {booking}, 'topup', 5)",
                '23514',
            ],
            'C5 refund without a booking' => [
                "INSERT INTO wallet_transactions (wallet_id, type, amount) VALUES ({wallet}, 'refund', 5)",
                '23514',
            ],
            'C5 unknown movement type' => [
                "INSERT INTO wallet_transactions (wallet_id, type, amount) VALUES ({wallet}, 'bonus', 5)",
                '23514',
            ],
            'C6 negative wallet balance' => [
                'UPDATE wallets SET balance = -0.001 WHERE id = {wallet}',
                '23514',
            ],
            'C7 second wallet for the same student' => [
                'INSERT INTO wallets (student_id, balance) VALUES ({student}, 0)',
                '23505',
            ],
            'C8 unknown booking status' => [
                "INSERT INTO bookings (student_id, session_id, status) VALUES ({student2}, {session}, 'pending')",
                '23514',
            ],
            'C8 waitlisted without a queue position' => [
                "INSERT INTO bookings (student_id, session_id, status) VALUES ({student2}, {session}, 'waitlisted')",
                '23514',
            ],
            'C11 confirmed without price and commission snapshot' => [
                "INSERT INTO bookings (student_id, session_id, status, confirmed_at) VALUES ({student2}, {session}, 'confirmed', now())",
                '23514',
            ],
            'C9 second active booking for the same student and session' => [
                "INSERT INTO bookings (student_id, session_id, status, waitlisted_at) VALUES ({student}, {session}, 'waitlisted', now())",
                '23505',
            ],
            'C12 unknown session status' => [
                "UPDATE sessions SET status = 'postponed' WHERE id = {session}",
                '23514',
            ],
            'C13 second refund for the same booking' => [
                "INSERT INTO wallet_transactions (wallet_id, booking_id, type, amount) VALUES ({wallet}, {booking}, 'refund', 10)",
                '23505',
            ],
            'C14 same idempotency key twice for one student' => [
                "INSERT INTO idempotency_keys (student_id, idempotency_key, request_hash) VALUES ({student}, 'k-1', repeat('a', 64))",
                '23505',
            ],
            'Session interval must be positive' => [
                'UPDATE sessions SET ends_at = starts_at WHERE id = {session}',
                '23514',
            ],
            'Commission rate above 100 percent' => [
                'UPDATE centers SET commission_rate = 1.5 WHERE id = {center}',
                '23514',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Seeded inside this test's transaction; ids are looked up, never assumed, so test order does not matter.
        $this->seed();

        $center = (int) DB::scalar('SELECT min(id) FROM centers');
        $student = (int) DB::scalar('SELECT min(id) FROM students');
        $session = (int) DB::scalar('SELECT min(id) FROM sessions WHERE center_id = ?', [$center]);
        $wallet = (int) DB::scalar('SELECT id FROM wallets WHERE student_id = ?', [$student]);

        // Fixture: the first student has a confirmed, charged and refunded booking, plus a used key.
        $booking = (int) DB::scalar(
            "INSERT INTO bookings (student_id, session_id, status, price_charged, commission_rate, commission_amount, confirmed_at)
             VALUES (?, ?, 'confirmed', 50, 0.1, 5, now()) RETURNING id",
            [$student, $session],
        );
        DB::insert("INSERT INTO wallet_transactions (wallet_id, booking_id, type, amount) VALUES (?, ?, 'charge', -50)", [$wallet, $booking]);
        DB::insert("INSERT INTO wallet_transactions (wallet_id, booking_id, type, amount) VALUES (?, ?, 'refund', 50)", [$wallet, $booking]);
        DB::insert("INSERT INTO idempotency_keys (student_id, idempotency_key, request_hash) VALUES (?, 'k-1', repeat('a', 64))", [$student]);

        $this->ids = [
            'center' => $center,
            'center2' => (int) DB::scalar('SELECT min(id) FROM centers WHERE id > ?', [$center]),
            'course' => (int) DB::scalar('SELECT course_id FROM sessions WHERE id = ?', [$session]),
            'session' => $session,
            'student' => $student,
            'student2' => (int) DB::scalar('SELECT min(id) FROM students WHERE id > ?', [$student]),
            'wallet' => $wallet,
            'ledger' => (int) DB::scalar('SELECT min(id) FROM wallet_transactions WHERE wallet_id = ?', [$wallet]),
            'booking' => $booking,
        ];
    }

    private function sql(string $template): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => (string) $this->ids[$m[1]], $template);
    }

    #[DataProvider('violations')]
    public function test_database_rejects(string $sql, string $sqlState): void
    {
        try {
            DB::statement($this->sql($sql));
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->getCode(), $e->getMessage());

            return;
        }

        $this->fail('The database accepted a statement that must be rejected.');
    }

    public function test_rebooking_after_cancellation_is_allowed(): void
    {
        DB::update("UPDATE bookings SET status = 'cancelled_by_student', cancelled_at = now() WHERE id = ?", [$this->ids['booking']]);

        DB::insert(
            "INSERT INTO bookings (student_id, session_id, status, waitlisted_at) VALUES (?, ?, 'waitlisted', now())",
            [$this->ids['student'], $this->ids['session']],
        );

        $this->assertSame(2, DB::table('bookings')->where('student_id', $this->ids['student'])->where('session_id', $this->ids['session'])->count());
    }

    public function test_seeded_wallets_match_their_ledger(): void
    {
        $mismatches = DB::select(
            'SELECT w.id FROM wallets w
             LEFT JOIN wallet_transactions t ON t.wallet_id = w.id
             GROUP BY w.id, w.balance
             HAVING w.balance <> COALESCE(SUM(t.amount), 0)'
        );

        $this->assertSame([], $mismatches);
    }
}
