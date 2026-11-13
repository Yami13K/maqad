<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class BookingNotCancellable extends DomainException
{
    public function __construct(string $message = 'Only a confirmed or waitlisted booking can be cancelled.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'booking_not_cancellable';
    }
}
