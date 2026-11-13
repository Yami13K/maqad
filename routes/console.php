<?php

declare(strict_types=1);

use App\Domain\Booking\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

// Idempotency keys are replayable for 24h, then deleted so the table stays small (ERD comment).
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->hourly();
