<?php

declare(strict_types=1);

namespace App\Domain\Booking\QueryBuilders;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Catalog\Models\CourseSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \App\Domain\Booking\Models\Booking
 *
 * @extends Builder<TModel>
 */
final class BookingQueryBuilder extends Builder
{
    public function ownedBy(int $studentId): self
    {
        return $this->where('bookings.student_id', $studentId);
    }

    /** Confirmed or waitlisted: holds a seat or a queue position (BR-07, Q3). */
    public function active(): self
    {
        return $this->whereIn('bookings.status', BookingStatus::activeValues());
    }

    public function onSession(int $sessionId): self
    {
        return $this->where('bookings.session_id', $sessionId);
    }

    /** Half-open intervals (Q3): a session ending at 10:00 does not clash with one starting at 10:00. */
    public function overlapping(CourseSession $session): self
    {
        return $this->join('sessions', 'sessions.id', '=', 'bookings.session_id')
            ->where('sessions.starts_at', '<', $session->ends_at)
            ->where('sessions.ends_at', '>', $session->starts_at);
    }

    /** BR-06 order: earliest waitlisted first, id breaks ties. */
    public function waitlistQueue(int $sessionId): self
    {
        return $this->onSession($sessionId)
            ->where('bookings.status', BookingStatus::Waitlisted->value)
            ->orderBy('bookings.waitlisted_at')
            ->orderBy('bookings.id');
    }
}
