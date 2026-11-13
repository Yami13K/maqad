<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

enum SessionStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';

    public function acceptsBookings(): bool
    {
        return $this === self::Scheduled;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Scheduled => $next === self::Cancelling,
            self::Cancelling => $next === self::Cancelled,
            self::Cancelled => false,
        };
    }
}
