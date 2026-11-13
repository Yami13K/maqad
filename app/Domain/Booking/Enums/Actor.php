<?php

declare(strict_types=1);

namespace App\Domain\Booking\Enums;

/** Who caused a booking status change, recorded on every audit row. */
enum Actor: string
{
    case Student = 'student';
    case System = 'system';
    case Center = 'center';
}
