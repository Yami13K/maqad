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
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->unique();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            // One wallet per student (C7).
            $table->foreignId('student_id')->unique()->constrained('students');
            // Cache of SUM(wallet_transactions.amount), written in the same transaction (C6).
            $table->decimal('balance', 12, 3)->default(0);
        });
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_nonnegative CHECK (balance >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('students');
    }
};
