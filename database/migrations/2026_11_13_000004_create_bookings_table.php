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
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('session_id')->constrained('sessions');
            $table->string('status');
            // Snapshots taken at confirmation, including promotion (C11, BR-09).
            $table->decimal('price_charged', 12, 3)->nullable();
            $table->decimal('commission_rate', 5, 4)->nullable();
            $table->decimal('commission_amount', 12, 3)->nullable();
            $table->timestampTz('waitlisted_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // Capacity count and waitlist order (C8).
            $table->index(['session_id', 'status', 'waitlisted_at', 'id']);
            // BR-07 overlap lookup, run under the student wallet lock.
            $table->index(['student_id', 'status']);
        });

        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_valid CHECK (status IN ('confirmed', 'waitlisted', 'cancelled_by_student', 'cancelled_by_center'))");
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_confirmed_has_snapshot CHECK (status <> 'confirmed' OR (price_charged IS NOT NULL AND commission_rate IS NOT NULL AND commission_amount IS NOT NULL AND confirmed_at IS NOT NULL))");
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_waitlisted_has_position CHECK (status <> 'waitlisted' OR waitlisted_at IS NOT NULL)");
        // Rebooking after cancellation is allowed (C9, BR-07): uniqueness covers active rows only.
        DB::statement("CREATE UNIQUE INDEX bookings_one_active_per_student_session ON bookings (student_id, session_id) WHERE status IN ('confirmed', 'waitlisted')");
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
