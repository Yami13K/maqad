<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Student\Models\Student;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Every student gets a wallet (C7). A balance is funded with a top-up ledger row, never by
 * writing the cache alone, so balance = SUM(ledger) holds in every test (C6).
 *
 * @extends Factory<Student>
 */
final class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->numerify('+9639########'),
        ];
    }

    public function configure(): self
    {
        return $this->afterCreating(function (Student $student): void {
            Wallet::query()->create(['student_id' => $student->id, 'balance' => '0.000']);
        });
    }

    public function withBalance(string $amount): self
    {
        return $this->afterCreating(function (Student $student) use ($amount): void {
            $wallet = Wallet::query()->where('student_id', $student->id)->firstOrFail();

            WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'type' => TransactionType::Topup,
                'amount' => $amount,
            ]);

            $wallet->update(['balance' => $amount]);
        });
    }
}
