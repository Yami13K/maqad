<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Wallet\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Pure value object: exact thousandths, half-up rounding applied once (Q1, C11, Q4). */
final class MoneyTest extends TestCase
{
    #[Test]
    public function it_parses_and_prints_three_decimals_exactly(): void
    {
        $this->assertSame('4.125', Money::of('4.125')->toDecimal());
        $this->assertSame('4.100', Money::of('4.1')->toDecimal());
        $this->assertSame('-50.000', Money::of('50')->negated()->toDecimal());
        $this->assertSame('0.300', Money::of('0.1')->plus(Money::of('0.2'))->toDecimal());
    }

    /** @return array<string, array{string, int, string}> */
    public static function percents(): array
    {
        return [
            'half of 42.125 rounds up' => ['42.125', 50, '21.063'],
            'half of 50.000 is exact' => ['50.000', 50, '25.000'],
            'full' => ['42.125', 100, '42.125'],
            'none' => ['42.125', 0, '0.000'],
            'half of 0.001 rounds up' => ['0.001', 50, '0.001'],
        ];
    }

    #[Test]
    #[DataProvider('percents')]
    public function percent_rounds_half_up(string $amount, int $percent, string $expected): void
    {
        $this->assertSame($expected, Money::of($amount)->percent($percent)->toDecimal());
    }

    /** @return array<string, array{string, string, string}> */
    public static function rates(): array
    {
        return [
            '42.125 x 15% = 6.31875' => ['42.125', '0.1500', '6.319'],
            '50 x 10%' => ['50.000', '0.1000', '5.000'],
            '0.005 x 10% = 0.0005' => ['0.005', '0.1000', '0.001'],
            '0.004 x 10% = 0.0004' => ['0.004', '0.1000', '0.000'],
            'full rate' => ['42.125', '1.0000', '42.125'],
        ];
    }

    #[Test]
    #[DataProvider('rates')]
    public function times_applies_a_four_decimal_rate_half_up(string $amount, string $rate, string $expected): void
    {
        $this->assertSame($expected, Money::of($amount)->times($rate)->toDecimal());
    }

    #[Test]
    public function it_rejects_floats_and_too_many_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1.2345');
    }
}
