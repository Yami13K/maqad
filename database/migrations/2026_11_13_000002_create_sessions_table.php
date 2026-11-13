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
        Schema::create('sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('branch_id');
            // Denormalised so both composite FKs share one center (C1, BR-01/02).
            $table->unsignedBigInteger('center_id');
            $table->timestampTz('starts_at');
            // Fixed at scheduling from the course duration (BR-07 overlap).
            $table->timestampTz('ends_at');
            $table->integer('capacity');
            // BR-04 backstop: changed only under the session row lock (C3).
            $table->integer('confirmed_count')->default(0);
            $table->decimal('price', 12, 3);
            $table->string('status')->default('scheduled');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['course_id', 'center_id'])->references(['id', 'center_id'])->on('courses');
            $table->foreign(['branch_id', 'center_id'])->references(['id', 'center_id'])->on('branches');
            $table->index(['branch_id', 'starts_at']);
        });

        DB::statement('ALTER TABLE sessions ADD CONSTRAINT sessions_capacity_positive CHECK (capacity > 0)');
        DB::statement('ALTER TABLE sessions ADD CONSTRAINT sessions_price_nonnegative CHECK (price >= 0)');
        DB::statement('ALTER TABLE sessions ADD CONSTRAINT sessions_interval_valid CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE sessions ADD CONSTRAINT sessions_confirmed_within_capacity CHECK (confirmed_count >= 0 AND confirmed_count <= capacity)');
        DB::statement("ALTER TABLE sessions ADD CONSTRAINT sessions_status_valid CHECK (status IN ('scheduled', 'cancelling', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
