<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

final class UnknownStudent extends DomainException
{
    public function __construct(string $message = 'Send a valid X-Student-Id header.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'unknown_student';
    }
}
