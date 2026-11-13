<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\CourseSession;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** E2 through HTTP: BR-08 refund tiers per branch time zone (C10, Q4), ownership, illegal transitions. */
final class CancelBookingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, string, int, string, string}> */
    public static function refundTiers(): array
    {
        // [branch zone, local start, its UTC instant, minutes before start, refund, balance after]
        $cases = [];
        foreach (['Asia/Damascus' => '2026-12-10 15:00:00', 'Asia/Muscat' => '2026-12-10 14:00:00'] as $zone => $utc) {
            foreach ([[48 * 60 + 1, '42.125'], [48 * 60 - 1, '21.063'], [12 * 60, '21.063'], [12 * 60 - 1, '0.000']] as [$minutes, $refund]) {
                $label = sprintf('%s %02d:%02d before', $zone, intdiv($minutes, 60), $minutes % 60);
                $balance = number_format(57.875 + (float) $refund, 3, '.', '');
                $cases[$label] = [$zone, '2026-12-10 18:00', $utc, $minutes, $refund, $balance];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('refundTiers')]
    public function T4_refund_follows_the_tier_measured_from_the_branch_local_start(
        string $zone, string $localStart, string $expectedUtc, int $minutesBefore, string $refund, string $balanceAfter,
    ): void {
        $clock = $this->freezeClock('2026-12-01 09:00:00');
        $branch = Branch::factory()->state(['timezone' => $zone])->create();
        // 18:00 on the branch's wall clock; price 42.125 so the 50% tier exercises half-up rounding (21.0625 -> 21.063).
        $session = CourseSession::factory()->at($branch)->startingAtLocal($localStart, $zone)->create(['price' => '42.125']);
        $this->assertSame($expectedUtc, $session->starts_at->utc()->format('Y-m-d H:i:s'));

        $student = Student::factory()->withBalance('100.000')->create();
        $bookingId = $this->book($student, $session->id)->assertStatus(201)->json('booking_id');

        $clock->setTo($session->starts_at->subMinutes($minutesBefore));
        $response = $this->cancel($student, $bookingId);

        $response->assertStatus(200)->assertExactJson([
            'booking_id' => $bookingId,
            'status' => 'cancelled_by_student',
            'charged_amount' => '42.125',
            'refunded_amount' => $refund,
            'promoted_booking_id' => null,
            'wallet_balance' => $balanceAfter,
        ]);
        // No ledger row for a zero refund (ERD comment); one refund row otherwise.
        $this->assertSame($refund === '0.000' ? 0 : 1, DB::table('wallet_transactions')->where(['booking_id' => $bookingId, 'type' => 'refund'])->count());
        $this->assertSame(0, CourseSession::query()->whereKey($session->id)->value('confirmed_count'));
        $this->assertLedgerMatchesBalances();
    }

    #[Test]
    public function cancelling_a_waitlisted_booking_moves_no_money(): void
    {
        $this->freezeClock();
        $session = CourseSession::factory()->create(['capacity' => 1]);
        $this->book(Student::factory()->withBalance('100.000')->create(), $session->id)->assertStatus(201);
        $student = Student::factory()->withBalance('100.000')->create();
        $bookingId = $this->book($student, $session->id)->assertStatus(202)->json('booking_id');

        $this->cancel($student, $bookingId)->assertStatus(200)->assertExactJson([
            'booking_id' => $bookingId,
            'status' => 'cancelled_by_student',
            'charged_amount' => '0.000',
            'refunded_amount' => '0.000',
            'promoted_booking_id' => null,
            'wallet_balance' => '100.000',
        ]);
        $this->assertSame(1, CourseSession::query()->whereKey($session->id)->value('confirmed_count'));
    }

    #[Test]
    public function a_booking_cannot_be_cancelled_twice_or_refunded_twice(): void
    {
        $this->freezeClock();
        $student = Student::factory()->withBalance('100.000')->create();
        $bookingId = $this->book($student, CourseSession::factory()->create()->id)->json('booking_id');
        $this->cancel($student, $bookingId)->assertStatus(200);

        $this->cancel($student, $bookingId)->assertStatus(409)->assertExactJson([
            'error' => ['code' => 'booking_not_cancellable', 'message' => 'Only a confirmed or waitlisted booking can be cancelled.'],
        ]);
        $this->assertSame(1, DB::table('wallet_transactions')->where(['booking_id' => $bookingId, 'type' => 'refund'])->count());
        $this->assertSame(
            ['confirmed', 'cancelled_by_student'],
            DB::table('booking_status_transitions')->where('booking_id', $bookingId)->orderBy('id')->pluck('to_status')->all(),
        );
    }

    #[Test]
    public function a_student_cannot_cancel_someone_elses_booking(): void
    {
        $this->freezeClock();
        $owner = Student::factory()->withBalance('100.000')->create();
        $bookingId = $this->book($owner, CourseSession::factory()->create()->id)->json('booking_id');

        $this->cancel(Student::factory()->create(), $bookingId)->assertStatus(404)->assertExactJson([
            'error' => ['code' => 'not_found', 'message' => 'Booking not found.'],
        ]);
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'confirmed']);
    }
}
