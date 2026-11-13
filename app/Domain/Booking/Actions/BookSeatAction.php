<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Data\BookSeatData;
use App\Domain\Booking\Enums\Actor;
use App\Domain\Booking\Exceptions\AlreadyBooked;
use App\Domain\Booking\Exceptions\InsufficientBalance;
use App\Domain\Booking\Exceptions\SessionNotBookable;
use App\Domain\Booking\Exceptions\TimeOverlap;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\IdempotencyGuard;
use App\Domain\Booking\Support\LockOrder;
use App\Domain\Catalog\Models\CourseSession;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Support\Ledger;
use App\Domain\Wallet\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * E1: book a seat, or join the waitlist when the session is full.
 *
 * One transaction: claim the idempotency key, lock the session row (serialises seats,
 * BR-04, T1), then lock the student's wallet (serialises this student across sessions,
 * so the overlap and balance checks cannot race, BR-07, T2), then write.
 */
final class BookSeatAction
{
    /** Deadlock or serialization failures are retried by Laravel up to this many attempts. */
    private const int ATTEMPTS = 3;

    public function __construct(
        private readonly IdempotencyGuard $idempotency,
        private readonly LockOrder $locks,
        private readonly Ledger $ledger,
        private readonly Clock $clock,
    ) {}

    public function execute(BookSeatData $data): BookingResult
    {
        return DB::transaction(function () use ($data): BookingResult {
            $replay = $this->idempotency->claim($data->studentId, $data->idempotencyKey, $data->requestHash);

            if ($replay !== null) {
                return $replay;
            }

            $result = $this->book($data->studentId, $data->sessionId);
            $this->idempotency->complete($data->studentId, $data->idempotencyKey, $result);

            return $result;
        }, self::ATTEMPTS);
    }

    private function book(int $studentId, int $sessionId): BookingResult
    {
        $now = $this->clock->now();
        $session = $this->locks->session($sessionId);

        if (! $session->isBookableAt($now)) {
            throw new SessionNotBookable;
        }

        $wallet = $this->locks->walletOf($studentId);

        $this->assertNotAlreadyBooked($studentId, $session);
        $this->assertNoOverlap($studentId, $session);

        return $session->hasFreeSeat()
            ? $this->confirm($studentId, $session, $wallet, $now)
            : $this->waitlist($studentId, $session, $wallet, $now);
    }

    private function confirm(int $studentId, CourseSession $session, Wallet $wallet, CarbonImmutable $now): BookingResult
    {
        $price = Money::of($session->price);

        if (Money::of($wallet->balance)->isLessThan($price)) {
            throw new InsufficientBalance;
        }

        $booking = new Booking(['student_id' => $studentId, 'session_id' => $session->id]);
        $booking->confirm($price, $session->center->commission_rate, Actor::Student, $now);
        $session->takeSeat();
        $this->ledger->charge($wallet, $booking, $price);

        return $this->result($booking, $price, $wallet, 201);
    }

    private function waitlist(int $studentId, CourseSession $session, Wallet $wallet, CarbonImmutable $now): BookingResult
    {
        // No charge and no hold: BR-06 charges on promotion.
        $booking = new Booking(['student_id' => $studentId, 'session_id' => $session->id]);
        $booking->joinWaitlist($now);

        return $this->result($booking, Money::zero(), $wallet, 202);
    }

    private function result(Booking $booking, Money $charged, Wallet $wallet, int $httpStatus): BookingResult
    {
        return new BookingResult(
            bookingId: $booking->id,
            status: $booking->status,
            chargedAmount: $charged,
            refundedAmount: Money::zero(),
            promotedBookingId: null,
            walletBalance: Money::of($wallet->balance),
            httpStatus: $httpStatus,
        );
    }

    private function assertNotAlreadyBooked(int $studentId, CourseSession $session): void
    {
        if (Booking::query()->ownedBy($studentId)->onSession($session->id)->active()->exists()) {
            throw new AlreadyBooked;
        }
    }

    private function assertNoOverlap(int $studentId, CourseSession $session): void
    {
        if (Booking::query()->ownedBy($studentId)->active()->overlapping($session)->exists()) {
            throw new TimeOverlap;
        }
    }
}
