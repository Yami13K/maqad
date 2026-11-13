<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * balance is a cache of SUM(transactions.amount). It is only written in the same
 * transaction as the ledger row, under SELECT ... FOR UPDATE on this wallet.
 */
class Wallet extends Model
{
    public $timestamps = false;

    protected $fillable = ['student_id', 'balance'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:3'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
