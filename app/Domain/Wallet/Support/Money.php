<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Support;

use InvalidArgumentException;

/**
 * Exact money in thousandths (3 decimals, Q1: one currency, OMR-safe), held as an int.
 * No floats anywhere: PostgreSQL numeric(12,3) strings in, strings out.
 */
final readonly class Money
{
    private const int SCALE = 1000;

    private function __construct(public int $thousandths) {}

    public static function of(string $decimal): self
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,3}))?$/', $decimal, $m) !== 1) {
            throw new InvalidArgumentException("Not a money amount: {$decimal}");
        }

        $value = (int) $m[2] * self::SCALE + (int) str_pad($m[3] ?? '', 3, '0');

        return new self($m[1] === '-' ? -$value : $value);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $other): self
    {
        return new self($this->thousandths + $other->thousandths);
    }

    public function minus(self $other): self
    {
        return new self($this->thousandths - $other->thousandths);
    }

    public function negated(): self
    {
        return new self(-$this->thousandths);
    }

    /** Percentage of a non-negative amount, rounded half-up to 3 decimals (BR-08 50% tier). */
    public function percent(int $percent): self
    {
        $this->assertNonNegative();

        return new self(intdiv($this->thousandths * $percent + 50, 100));
    }

    /**
     * Multiply by a rate with 4 decimals, e.g. "0.1500", rounded half-up once (BR-09).
     */
    public function times(string $rate): self
    {
        $this->assertNonNegative();

        if (preg_match('/^(\d)(?:\.(\d{1,4}))?$/', $rate, $m) !== 1) {
            throw new InvalidArgumentException("Not a rate: {$rate}");
        }

        $rateTenThousandths = (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '', 4, '0');

        return new self(intdiv($this->thousandths * $rateTenThousandths + 5000, 10000));
    }

    public function isPositive(): bool
    {
        return $this->thousandths > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->thousandths < $other->thousandths;
    }

    public function toDecimal(): string
    {
        $abs = abs($this->thousandths);

        return sprintf('%s%d.%03d', $this->thousandths < 0 ? '-' : '', intdiv($abs, self::SCALE), $abs % self::SCALE);
    }

    private function assertNonNegative(): void
    {
        if ($this->thousandths < 0) {
            throw new InvalidArgumentException('Rounding rules here are defined for non-negative amounts only.');
        }
    }
}
