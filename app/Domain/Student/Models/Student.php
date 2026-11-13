<?php

declare(strict_types=1);

namespace App\Domain\Student\Models;

use App\Domain\Booking\Models\Booking;
use App\Domain\Wallet\Models\Wallet;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[UseFactory(StudentFactory::class)]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['full_name', 'phone'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
