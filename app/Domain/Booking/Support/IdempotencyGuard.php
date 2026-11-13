<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Exceptions\IdempotencyKeyReused;
use App\Domain\Booking\Models\IdempotencyKey;
use Illuminate\Support\Facades\DB;

/**
 * BR-11 / C14. Runs inside the booking transaction, so the key and the booking commit
 * together or not at all:
 * - the INSERT claims the key; a concurrent duplicate blocks on the unique index until
 *   the first transaction commits, then hits the conflict and replays the stored result;
 * - if the booking throws, the claim rolls back with it, so a retry is evaluated afresh.
 */
final class IdempotencyGuard
{
    /** Returns the stored result for a replay, or null when this request claimed the key. */
    public function claim(int $studentId, string $key, string $requestHash): ?BookingResult
    {
        $claimed = DB::selectOne(
            'INSERT INTO idempotency_keys (student_id, idempotency_key, request_hash)
             VALUES (?, ?, ?)
             ON CONFLICT (student_id, idempotency_key) DO NOTHING
             RETURNING id',
            [$studentId, $key, $requestHash],
        );

        if ($claimed !== null) {
            return null;
        }

        $stored = $this->find($studentId, $key);

        if (! hash_equals($stored->request_hash, $requestHash)) {
            throw new IdempotencyKeyReused;
        }

        return BookingResult::fromStored($stored->response_body, (int) $stored->response_status);
    }

    public function complete(int $studentId, string $key, BookingResult $result): void
    {
        $this->find($studentId, $key)->forceFill([
            'response_status' => $result->httpStatus,
            'response_body' => $result->toArray(),
        ])->save();
    }

    private function find(int $studentId, string $key): IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('student_id', $studentId)
            ->where('idempotency_key', $key)
            ->firstOrFail();
    }
}
