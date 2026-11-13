<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * A business rule said no. Each subclass names its HTTP status and a stable error code;
 * only bootstrap/app.php turns it into a response, so the domain never knows about HTTP.
 */
abstract class DomainException extends RuntimeException
{
    abstract public function status(): int;

    abstract public function errorCode(): string;
}
