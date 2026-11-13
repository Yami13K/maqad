<?php

declare(strict_types=1);

namespace App\Domain\Booking\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Waitlisted = 'waitlisted';
    case CancelledByStudent = 'cancelled_by_student';
    case CancelledByCenter = 'cancelled_by_center';

    /** Active bookings hold a seat or a queue position and block overlap (BR-07, Q3). */
    public function isActive(): bool
    {
        return $this === self::Confirmed || $this === self::Waitlisted;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Waitlisted => in_array($next, [self::Confirmed, self::CancelledByStudent, self::CancelledByCenter], true),
            self::Confirmed => in_array($next, [self::CancelledByStudent, self::CancelledByCenter], true),
            self::CancelledByStudent, self::CancelledByCenter => false,
        };
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Confirmed->value, self::Waitlisted->value];
    }
}
