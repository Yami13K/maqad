<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

final class InvalidRequest extends DomainException
{
    public function __construct(string $message = 'The request is invalid.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'invalid_request';
    }
}
