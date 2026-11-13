<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use Carbon\CarbonImmutable;

/**
 * BR-08 tiers, measured between two UTC instants (Q4). Because starts_at is stored as the
 * instant of the branch-local start time (C10), Damascus and Muscat need no special case.
 */
final class RefundPolicy
{
    private const int FULL_REFUND_FROM_MINUTES = 48 * 60;

    private const int HALF_REFUND_FROM_MINUTES = 12 * 60;

    public function percentFor(CarbonImmutable $startsAt, CarbonImmutable $now): int
    {
        $secondsLeft = $now->diffInSeconds($startsAt, false);

        return match (true) {
            $secondsLeft >= self::FULL_REFUND_FROM_MINUTES * 60 => 100,
            $secondsLeft >= self::HALF_REFUND_FROM_MINUTES * 60 => 50,
            default => 0,
        };
    }
}
