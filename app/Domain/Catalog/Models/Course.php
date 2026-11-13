<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(CourseFactory::class)]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['center_id', 'title', 'duration_minutes'];

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer'];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CourseSession::class);
    }
}
