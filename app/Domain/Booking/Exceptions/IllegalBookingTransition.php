<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Shared\Exceptions\DomainException;

/** A status change the enum does not allow. Thrown, never swallowed, so double processing surfaces. */
final class IllegalBookingTransition extends DomainException
{
    public static function between(int $bookingId, ?BookingStatus $from, BookingStatus $to): self
    {
        return new self(sprintf(
            'Booking %d cannot move from %s to %s.',
            $bookingId,
            $from->value ?? 'new',
            $to->value,
        ));
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'illegal_transition';
    }
}
