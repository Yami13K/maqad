<?php

declare(strict_types=1);

namespace App\Domain\Booking\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * BR-06: a waitlisted student was passed over for lack of balance and stays in the queue (Q2 comment).
 * Dispatched after commit; delivery (push, SMS) is out of scope.
 */
final class WaitlistedStudentSkipped implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $studentId,
        public readonly int $sessionId,
    ) {}
}
