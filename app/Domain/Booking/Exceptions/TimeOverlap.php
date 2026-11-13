<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class TimeOverlap extends DomainException
{
    public function __construct(string $message = 'The student already holds a booking that overlaps this session.')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'time_overlap';
    }
}
