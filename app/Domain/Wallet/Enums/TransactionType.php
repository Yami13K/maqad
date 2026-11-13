<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

enum TransactionType: string
{
    case Topup = 'topup';
    case Charge = 'charge';
    case Refund = 'refund';

    /** Sign rule mirrored by the wallet_transactions CHECK: charges are negative, the rest positive. */
    public function isDebit(): bool
    {
        return $this === self::Charge;
    }

    public function requiresBooking(): bool
    {
        return $this !== self::Topup;
    }
}
