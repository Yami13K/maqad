<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

final readonly class CancelBookingData
{
    public function __construct(
        public int $studentId,
        public int $bookingId,
    ) {}
}
