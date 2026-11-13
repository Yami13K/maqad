<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Support;

use App\Domain\Booking\Models\Booking;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;

/**
 * The only writer of money. Caller must hold the wallet row lock (FOR UPDATE) inside the
 * current transaction, so the ledger row and the balance cache move together (C6, BR-05).
 */
final class Ledger
{
    public function charge(Wallet $wallet, Booking $booking, Money $price): void
    {
        // A free session (price 0, allowed by the schema) writes no row, mirroring refund().
        if ($price->isPositive()) {
            $this->record($wallet, $booking, TransactionType::Charge, $price->negated());
        }
    }

    public function refund(Wallet $wallet, Booking $booking, Money $amount): void
    {
        // A zero refund (under 12h) writes no row: the ledger only holds real movements.
        if ($amount->isPositive()) {
            $this->record($wallet, $booking, TransactionType::Refund, $amount);
        }
    }

    private function record(Wallet $wallet, Booking $booking, TransactionType $type, Money $signedAmount): void
    {
        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'booking_id' => $booking->id,
            'type' => $type,
            'amount' => $signedAmount->toDecimal(),
        ]);

        $wallet->balance = Money::of($wallet->balance)->plus($signedAmount)->toDecimal();
        $wallet->save();
    }
}
