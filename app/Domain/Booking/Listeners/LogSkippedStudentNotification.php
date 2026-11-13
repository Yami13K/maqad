<?php

declare(strict_types=1);

namespace App\Domain\Booking\Listeners;

use App\Domain\Booking\Events\WaitlistedStudentSkipped;
use Illuminate\Support\Facades\Log;

final class LogSkippedStudentNotification
{
    public function handle(WaitlistedStudentSkipped $event): void
    {
        Log::info('Waitlisted student skipped for insufficient balance; notification would be sent here.', [
            'booking_id' => $event->bookingId,
            'student_id' => $event->studentId,
            'session_id' => $event->sessionId,
        ]);
    }
}
