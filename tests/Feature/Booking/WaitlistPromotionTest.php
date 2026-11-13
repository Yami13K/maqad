<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Booking\Events\WaitlistedStudentSkipped;
use App\Domain\Catalog\Models\CourseSession;
use App\Domain\Student\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BR-06 promotion through E2, and BR-07/C9 rebooking after a cancel. */
final class WaitlistPromotionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function T5_cancel_skips_the_unpayable_earliest_promotes_the_next_and_the_canceller_can_rejoin(): void
    {
        Event::fake([WaitlistedStudentSkipped::class]);
        $clock = $this->freezeClock('2026-12-01 09:00:00');
        $session = CourseSession::factory()->create(['capacity' => 1, 'price' => '50.000']);

        $a = Student::factory()->withBalance('100.000')->create();
        $poor = Student::factory()->withBalance('10.000')->create();
        $next = Student::factory()->withBalance('80.000')->create();

        $aBooking = $this->book($a, $session->id)->assertStatus(201)->json('booking_id');
        $clock->advanceSeconds(60);
        $poorBooking = $this->book($poor, $session->id)->assertStatus(202)->json('booking_id');
        $clock->advanceSeconds(60);
        $nextBooking = $this->book($next, $session->id)->assertStatus(202)->json('booking_id');

        $this->cancel($a, $aBooking)->assertStatus(200)->assertExactJson([
            'booking_id' => $aBooking,
            'status' => 'cancelled_by_student',
            'charged_amount' => '50.000',
            'refunded_amount' => '50.000',
            'promoted_booking_id' => $nextBooking,
            'wallet_balance' => '100.000',
        ]);

        $rebook = $this->book($a, $session->id);

        $rebook->assertStatus(202)->assertExactJson([
            'booking_id' => $rebook->json('booking_id'),
            'status' => 'waitlisted',
            'charged_amount' => '0.000',
            'refunded_amount' => '0.000',
            'promoted_booking_id' => null,
            'wallet_balance' => '100.000',
        ]);

        // The earliest was skipped, keeps their place, and is told so after commit.
        $this->assertDatabaseHas('bookings', ['id' => $poorBooking, 'status' => 'waitlisted', 'price_charged' => null]);
        Event::assertDispatched(WaitlistedStudentSkipped::class, fn (WaitlistedStudentSkipped $e): bool => $e->bookingId === $poorBooking);
        $this->assertSame('10.000', $this->balanceOf($poor));

        // The second was promoted at the promotion-time price with snapshots, charged once, by the system.
        $this->assertDatabaseHas('bookings', ['id' => $nextBooking, 'status' => 'confirmed', 'price_charged' => '50.000', 'confirmed_at' => '2026-12-01 09:02:00+00']);
        $this->assertSame(['-50.000'], DB::table('wallet_transactions')->where('booking_id', $nextBooking)->pluck('amount')->all());
        $this->assertDatabaseHas('booking_status_transitions', ['booking_id' => $nextBooking, 'from_status' => 'waitlisted', 'to_status' => 'confirmed', 'actor' => 'system']);
        $this->assertSame('30.000', $this->balanceOf($next));

        // The seat count never moved past capacity, and the ledger still adds up.
        $this->assertSame(1, CourseSession::query()->whereKey($session->id)->value('confirmed_count'));
        $this->assertLedgerMatchesBalances();
    }

    #[Test]
    public function nobody_is_promoted_when_no_waitlisted_student_can_pay(): void
    {
        Event::fake([WaitlistedStudentSkipped::class]);
        $this->freezeClock();
        $session = CourseSession::factory()->create(['capacity' => 1, 'price' => '50.000']);
        $a = Student::factory()->withBalance('100.000')->create();
        $aBooking = $this->book($a, $session->id)->json('booking_id');
        $this->book(Student::factory()->withBalance('10.000')->create(), $session->id)->assertStatus(202);

        $this->cancel($a, $aBooking)->assertStatus(200)->assertJsonPath('promoted_booking_id', null);

        $this->assertSame(0, CourseSession::query()->whereKey($session->id)->value('confirmed_count'));
        Event::assertDispatchedTimes(WaitlistedStudentSkipped::class, 1);
    }
}
