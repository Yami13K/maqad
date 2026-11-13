<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Booking\Models\Booking;
use App\Domain\Wallet\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only ledger row. The database trigger is the real guard; this mirrors it in PHP. */
class WalletTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['wallet_id', 'booking_id', 'type', 'amount'];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'decimal:3',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Ledger rows are append-only.'));
        static::deleting(static fn (): never => throw new LogicException('Ledger rows are append-only.'));
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
