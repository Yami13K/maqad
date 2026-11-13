<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

final class ResourceNotFound extends DomainException
{
    public function __construct(string $message = 'Resource not found.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'not_found';
    }
}
