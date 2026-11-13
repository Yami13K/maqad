<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class SessionNotBookable extends DomainException
{
    public function __construct(string $message = 'The session is not open for booking.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'session_not_bookable';
    }
}
