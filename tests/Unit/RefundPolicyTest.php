<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Booking\Support\RefundPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** BR-08 tiers at the second level: 48:00 and 12:00 belong to the higher tier (Q4). */
final class RefundPolicyTest extends TestCase
{
    /** @return array<string, array{int, int}> */
    public static function tiers(): array
    {
        return [
            '48:00:00 exactly' => [48 * 3600, 100],
            '47:59:59' => [48 * 3600 - 1, 50],
            '12:00:00 exactly' => [12 * 3600, 50],
            '11:59:59' => [12 * 3600 - 1, 0],
            'already started' => [-60, 0],
        ];
    }

    #[Test]
    #[DataProvider('tiers')]
    public function the_tier_depends_only_on_seconds_left(int $secondsBefore, int $percent): void
    {
        $startsAt = CarbonImmutable::parse('2026-12-10 15:00:00', 'UTC');

        $this->assertSame($percent, (new RefundPolicy)->percentFor($startsAt, $startsAt->subSeconds($secondsBefore)));
    }
}
