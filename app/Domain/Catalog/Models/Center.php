<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Database\Factories\CenterFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(CenterFactory::class)]
class Center extends Model
{
    /** @use HasFactory<CenterFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['name', 'commission_rate'];

    protected function casts(): array
    {
        return ['commission_rate' => 'decimal:4', 'created_at' => 'immutable_datetime'];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
