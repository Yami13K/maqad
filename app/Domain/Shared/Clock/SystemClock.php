<?php

declare(strict_types=1);

namespace App\Domain\Shared\Clock;

use Carbon\CarbonImmutable;

final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
