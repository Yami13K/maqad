<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Queries;

use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;

/** E3 read model: balance plus every ledger row, oldest first. Changes nothing. */
final class WalletStatement
{
    /** @return array{wallet_balance: string, transactions: list<array{id: int, type: string, amount: string, booking_id: int|null, created_at: string}>} */
    public function forStudent(int $studentId): array
    {
        $wallet = Wallet::query()->where('student_id', $studentId)->firstOrFail();

        return [
            'wallet_balance' => $wallet->balance,
            'transactions' => $wallet->transactions()
                ->orderBy('id')
                ->get()
                ->map(static fn (WalletTransaction $t): array => [
                    'id' => $t->id,
                    'type' => $t->type->value,
                    'amount' => $t->amount,
                    'booking_id' => $t->booking_id,
                    'created_at' => $t->created_at->toIso8601ZuluString(),
                ])
                ->all(),
        ];
    }
}
