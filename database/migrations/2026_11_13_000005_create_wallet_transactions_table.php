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
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->foreignId('booking_id')->nullable()->constrained('bookings');
            $table->string('type');
            // Signed: charges negative, top-ups and refunds positive, so balance = SUM(amount) (C5).
            $table->decimal('amount', 12, 3);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['wallet_id', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_shape CHECK (
                (type = 'topup'  AND booking_id IS NULL     AND amount > 0) OR
                (type = 'charge' AND booking_id IS NOT NULL AND amount < 0) OR
                (type = 'refund' AND booking_id IS NOT NULL AND amount > 0)
            )
            SQL);

        // One charge and one refund per booking, whatever retries or re-runs happen (C13, BR-10).
        DB::statement('CREATE UNIQUE INDEX wallet_transactions_once_per_booking ON wallet_transactions (booking_id, type) WHERE booking_id IS NOT NULL');

        // Append-only ledger (C4, BR-05): the database refuses edits and deletes.
        // OR REPLACE: migrate:fresh drops tables but not functions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION wallet_transactions_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'wallet_transactions is append-only: % is not allowed', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER wallet_transactions_no_update_delete
                BEFORE UPDATE OR DELETE ON wallet_transactions
                FOR EACH ROW EXECUTE FUNCTION wallet_transactions_append_only();

            CREATE TRIGGER wallet_transactions_no_truncate
                BEFORE TRUNCATE ON wallet_transactions
                FOR EACH STATEMENT EXECUTE FUNCTION wallet_transactions_append_only();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_no_truncate ON wallet_transactions');
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_no_update_delete ON wallet_transactions');
        Schema::dropIfExists('wallet_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS wallet_transactions_append_only()');
    }
};
