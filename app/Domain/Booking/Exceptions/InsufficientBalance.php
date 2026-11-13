<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class InsufficientBalance extends DomainException
{
    public function __construct(string $message = 'Wallet balance is lower than the session price.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 402;
    }

    public function errorCode(): string
    {
        return 'insufficient_balance';
    }
}
