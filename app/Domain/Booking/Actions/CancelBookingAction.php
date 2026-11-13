<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Data\CancelBookingData;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\LockOrder;
use App\Domain\Booking\Support\RefundPolicy;
use App\Domain\Booking\Support\WaitlistPromoter;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\Exceptions\ResourceNotFound;
use App\Domain\Wallet\Support\Ledger;
use App\Domain\Wallet\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * E2: the student cancels (BR-08 refund tiers), then the freed seat goes to the
 * waitlist (BR-06). One transaction, lock order: session, booking, wallet.
 */
final class CancelBookingAction
{
    private const int ATTEMPTS = 3;

    public function __construct(
        private readonly LockOrder $locks,
        private readonly Ledger $ledger,
        private readonly RefundPolicy $refunds,
        private readonly WaitlistPromoter $promoter,
        private readonly Clock $clock,
    ) {}

    public function execute(CancelBookingData $data): BookingResult
    {
        return DB::transaction(fn (): BookingResult => $this->cancel($data), self::ATTEMPTS);
    }

    private function cancel(CancelBookingData $data): BookingResult
    {
        $now = $this->clock->now();

        // Unlocked read only to learn the session; everything is re-read under locks below.
        $sessionId = Booking::query()
            ->whereKey($data->bookingId)
            ->ownedBy($data->studentId)
            ->value('session_id') ?? throw new ResourceNotFound('Booking not found.');

        $session = $this->locks->session($sessionId);
        $booking = Booking::query()->lockForUpdate()->findOrFail($data->bookingId);
        $wallet = $this->locks->walletOf($data->studentId);

        $wasConfirmed = $booking->status === BookingStatus::Confirmed;
        $booking->cancelByStudent($now);

        if (! $wasConfirmed) {
            // A waitlisted booking was never charged and frees no seat.
            return $this->result($booking, Money::zero(), Money::zero(), null, $wallet->balance);
        }

        $charged = Money::of($booking->price_charged);
        $refund = $charged->percent($this->refunds->percentFor($session->starts_at, $now));

        $this->ledger->refund($wallet, $booking, $refund);
        $session->releaseSeat();

        $promoted = $this->promoter->promoteNext($session, $now);

        return $this->result($booking, $charged, $refund, $promoted?->id, $wallet->balance);
    }

    private function result(Booking $booking, Money $charged, Money $refund, ?int $promotedId, string $balance): BookingResult
    {
        return new BookingResult(
            bookingId: $booking->id,
            status: $booking->status,
            chargedAmount: $charged,
            refundedAmount: $refund,
            promotedBookingId: $promotedId,
            walletBalance: Money::of($balance),
            httpStatus: 200,
        );
    }
}
