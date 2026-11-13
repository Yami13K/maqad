<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // Current contract rate only; bookings snapshot it at confirmation (C11, BR-09).
            $table->decimal('commission_rate', 5, 4);
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE centers ADD CONSTRAINT centers_commission_rate_range CHECK (commission_rate >= 0 AND commission_rate <= 1)');

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained('centers');
            $table->string('name');
            $table->string('city');
            // IANA zone, e.g. Asia/Damascus or Asia/Muscat (C10, BR-08).
            $table->string('timezone');
            // Target of the sessions composite FK (C1).
            $table->unique(['id', 'center_id']);
        });

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('center_id')->constrained('centers');
            $table->string('title');
            $table->integer('duration_minutes');
            // Target of the sessions composite FK (C1). Price lives on sessions (C2).
            $table->unique(['id', 'center_id']);
        });
        DB::statement('ALTER TABLE courses ADD CONSTRAINT courses_duration_positive CHECK (duration_minutes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('centers');
    }
};
