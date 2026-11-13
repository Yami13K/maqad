<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class IdempotencyKeyReused extends DomainException
{
    public function __construct(string $message = 'This Idempotency-Key was already used with a different request.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'idempotency_key_reused';
    }
}
