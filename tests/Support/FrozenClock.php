<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\Clock\Clock;
use Carbon\CarbonImmutable;

/** Test adapter for the Clock port: time moves only when the test says so. */
final class FrozenClock implements Clock
{
    public function __construct(private CarbonImmutable $now) {}

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function setTo(CarbonImmutable $now): void
    {
        $this->now = $now->utc();
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->addSeconds($seconds);
    }
}
