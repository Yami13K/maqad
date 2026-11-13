<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Booking\Models\Booking;
use App\Domain\Catalog\Enums\SessionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CourseSessionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scheduled session of a course at a branch (the SESSIONS table).
 * Named CourseSession to avoid confusion with Laravel's HTTP session.
 */
#[UseFactory(CourseSessionFactory::class)]
class CourseSession extends Model
{
    /** @use HasFactory<CourseSessionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'sessions';

    protected $fillable = [
        'course_id', 'branch_id', 'center_id', 'starts_at', 'ends_at',
        'capacity', 'confirmed_count', 'price', 'status', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'confirmed_count' => 'integer',
            'price' => 'decimal:3',
            'status' => SessionStatus::class,
        ];
    }

    /** Open for new bookings (C12) and not yet started. */
    public function isBookableAt(CarbonImmutable $now): bool
    {
        return $this->status->acceptsBookings() && $this->starts_at->greaterThan($now);
    }

    public function hasFreeSeat(): bool
    {
        return $this->confirmed_count < $this->capacity;
    }

    /**
     * Caller holds this row's lock (FOR UPDATE). CHECK (confirmed_count <= capacity) is the
     * backstop if a code path ever skips the lock (C3, BR-04).
     */
    public function takeSeat(): void
    {
        $this->increment('confirmed_count');
    }

    public function releaseSeat(): void
    {
        $this->decrement('confirmed_count');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'session_id');
    }
}
