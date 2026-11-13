<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Wallet\Support\Money;

/** The fixed E1/E2 response shape from the task, plus the HTTP status it maps to. */
final readonly class BookingResult
{
    public function __construct(
        public int $bookingId,
        public BookingStatus $status,
        public Money $chargedAmount,
        public Money $refundedAmount,
        public ?int $promotedBookingId,
        public Money $walletBalance,
        public int $httpStatus,
        public bool $replayed = false,
    ) {}

    /** Rebuild a stored response for an idempotent replay (T3: same response, byte for byte). */
    /** @param array{booking_id: int, status: string, charged_amount: string, refunded_amount: string, promoted_booking_id: int|null, wallet_balance: string} $body */
    public static function fromStored(array $body, int $httpStatus): self
    {
        return new self(
            bookingId: (int) $body['booking_id'],
            status: BookingStatus::from($body['status']),
            chargedAmount: Money::of($body['charged_amount']),
            refundedAmount: Money::of($body['refunded_amount']),
            promotedBookingId: $body['promoted_booking_id'] === null ? null : (int) $body['promoted_booking_id'],
            walletBalance: Money::of($body['wallet_balance']),
            httpStatus: $httpStatus,
            replayed: true,
        );
    }

    /** @return array{booking_id: int, status: string, charged_amount: string, refunded_amount: string, promoted_booking_id: int|null, wallet_balance: string} */
    public function toArray(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'status' => $this->status->value,
            'charged_amount' => $this->chargedAmount->toDecimal(),
            'refunded_amount' => $this->refundedAmount->toDecimal(),
            'promoted_booking_id' => $this->promotedBookingId,
            'wallet_balance' => $this->walletBalance->toDecimal(),
        ];
    }
}
