<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Student\Models\Student;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 60 students, each with 1000 in the wallet. The opening balance is a top-up
 * ledger row, so balance = SUM(ledger) holds from the first row (C6, BR-05).
 */
class StudentSeeder extends Seeder
{
    private const int STUDENTS = 60;

    private const string OPENING_BALANCE = '1000.000';

    public function run(): void
    {
        DB::transaction(function (): void {
            for ($i = 1; $i <= self::STUDENTS; $i++) {
                $student = Student::query()->create([
                    'full_name' => sprintf('Student %02d', $i),
                    'phone' => sprintf('+963900000%03d', $i),
                ]);

                $wallet = Wallet::query()->create([
                    'student_id' => $student->id,
                    'balance' => self::OPENING_BALANCE,
                ]);

                WalletTransaction::query()->create([
                    'wallet_id' => $wallet->id,
                    'type' => TransactionType::Topup,
                    'amount' => self::OPENING_BALANCE,
                ]);
            }
        });
    }
}
