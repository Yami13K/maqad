<?php

declare(strict_types=1);

namespace App\Domain\Booking\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/** BR-11 / C14. A key is replayable for 24h; after that `model:prune` deletes it (ERD comment). */
class IdempotencyKey extends Model
{
    use Prunable;

    public const int RETENTION_HOURS = 24;

    public const UPDATED_AT = null;

    protected $fillable = ['student_id', 'idempotency_key', 'request_hash', 'response_status', 'response_body'];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }
}
