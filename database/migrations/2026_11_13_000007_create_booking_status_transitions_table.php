<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail: one row per booking status change (from, to, actor, time).
 * Not in the analysis ERD; added because every money-moving transition must be traceable.
 * Append-only like the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_status_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('actor');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['booking_id', 'id']);
        });

        $statuses = "'confirmed','waitlisted','cancelled_by_student','cancelled_by_center'";

        DB::statement("ALTER TABLE booking_status_transitions ADD CONSTRAINT booking_status_transitions_to_check CHECK (to_status IN ({$statuses}))");
        DB::statement("ALTER TABLE booking_status_transitions ADD CONSTRAINT booking_status_transitions_from_check CHECK (from_status IS NULL OR from_status IN ({$statuses}))");
        DB::statement("ALTER TABLE booking_status_transitions ADD CONSTRAINT booking_status_transitions_actor_check CHECK (actor IN ('student','system','center'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION booking_status_transitions_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'booking_status_transitions is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER booking_status_transitions_no_update_delete
                BEFORE UPDATE OR DELETE ON booking_status_transitions
                FOR EACH ROW EXECUTE FUNCTION booking_status_transitions_append_only();

            CREATE TRIGGER booking_status_transitions_no_truncate
                BEFORE TRUNCATE ON booking_status_transitions
                FOR EACH STATEMENT EXECUTE FUNCTION booking_status_transitions_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_transitions');
        DB::unprepared('DROP FUNCTION IF EXISTS booking_status_transitions_append_only()');
    }
};
