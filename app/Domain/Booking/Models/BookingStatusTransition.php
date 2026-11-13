<?php

declare(strict_types=1);

namespace App\Domain\Booking\Models;

use App\Domain\Booking\Enums\Actor;
use App\Domain\Booking\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only audit row; the database trigger rejects UPDATE and DELETE. Written only by Booking::moveTo(). */
class BookingStatusTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['booking_id', 'from_status', 'to_status', 'actor'];

    protected function casts(): array
    {
        return [
            'from_status' => BookingStatus::class,
            'to_status' => BookingStatus::class,
            'actor' => Actor::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
