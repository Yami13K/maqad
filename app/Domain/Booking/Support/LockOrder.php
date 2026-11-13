<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Catalog\Models\CourseSession;
use App\Domain\Shared\Exceptions\ResourceNotFound;
use App\Domain\Wallet\Models\Wallet;

/**
 * One lock order everywhere: the session row first, then wallet rows (ERD header, D1).
 * Every method must run inside DB::transaction().
 */
final class LockOrder
{
    public function session(int $sessionId): CourseSession
    {
        return CourseSession::query()->with('center')->lockForUpdate()->find($sessionId)
            ?? throw new ResourceNotFound('Session not found.');
    }

    public function walletOf(int $studentId): Wallet
    {
        // Wallets are provisioned with the student, so a missing one is a data error.
        return Wallet::query()->where('student_id', $studentId)->lockForUpdate()->firstOrFail();
    }
}
