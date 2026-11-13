<?php

declare(strict_types=1);

namespace App\Domain\Booking\Models;

use App\Domain\Booking\Enums\Actor;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\BookingNotCancellable;
use App\Domain\Booking\Exceptions\IllegalBookingTransition;
use App\Domain\Booking\QueryBuilders\BookingQueryBuilder;
use App\Domain\Catalog\Models\CourseSession;
use App\Domain\Student\Models\Student;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Status changes only through the transition methods below. Each one checks the enum,
 * saves, and writes an audit row in the caller's transaction.
 *
 * @method static BookingQueryBuilder query()
 */
class Booking extends Model
{
    public const UPDATED_AT = null;

    /** Status and money snapshots are set only through the transition methods. */
    protected $fillable = ['student_id', 'session_id'];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'price_charged' => 'decimal:3',
            'commission_rate' => 'decimal:4',
            'commission_amount' => 'decimal:3',
            'waitlisted_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function newEloquentBuilder($query): BookingQueryBuilder
    {
        return new BookingQueryBuilder($query);
    }

    /** New booking with a free seat, or promotion from the waitlist (BR-06). Snapshots price and commission (C11). */
    public function confirm(Money $price, string $commissionRate, Actor $actor, CarbonImmutable $now): void
    {
        $this->price_charged = $price->toDecimal();
        $this->commission_rate = $commissionRate;
        $this->commission_amount = $price->times($commissionRate)->toDecimal();
        $this->confirmed_at = $now;

        $this->moveTo(BookingStatus::Confirmed, $actor);
    }

    public function joinWaitlist(CarbonImmutable $now): void
    {
        $this->waitlisted_at = $now;

        $this->moveTo(BookingStatus::Waitlisted, Actor::Student);
    }

    public function cancelByStudent(CarbonImmutable $now): void
    {
        if (! $this->status->canTransitionTo(BookingStatus::CancelledByStudent)) {
            throw new BookingNotCancellable;
        }

        $this->cancelled_at = $now;

        $this->moveTo(BookingStatus::CancelledByStudent, Actor::Student);
    }

    private function moveTo(BookingStatus $next, Actor $actor): void
    {
        $from = $this->exists ? $this->status : null;

        if ($from !== null && ! $from->canTransitionTo($next)) {
            throw IllegalBookingTransition::between($this->id, $from, $next);
        }

        $this->status = $next;
        $this->save();

        $this->transitions()->create([
            'from_status' => $from,
            'to_status' => $next,
            'actor' => $actor,
        ]);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CourseSession::class, 'session_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(BookingStatusTransition::class);
    }
}
