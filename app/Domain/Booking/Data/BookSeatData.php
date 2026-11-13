<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use App\Domain\Shared\Exceptions\InvalidRequest;

final readonly class BookSeatData
{
    private const int MAX_KEY_LENGTH = 255;

    public function __construct(
        public int $studentId,
        public int $sessionId,
        public string $idempotencyKey,
        public string $requestHash,
    ) {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > self::MAX_KEY_LENGTH) {
            throw new InvalidRequest('The Idempotency-Key header is required (1 to 255 characters).');
        }
    }
}
