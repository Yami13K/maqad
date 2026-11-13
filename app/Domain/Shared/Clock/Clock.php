<?php

declare(strict_types=1);

namespace App\Domain\Shared\Clock;

use Carbon\CarbonImmutable;

/** Port for "now", so refund tiers and queue order are testable at exact boundaries (T4). */
interface Clock
{
    public function now(): CarbonImmutable;
}
