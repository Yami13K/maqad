<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Domain\Booking\Enums\Actor;
use App\Domain\Booking\Events\WaitlistedStudentSkipped;
use App\Domain\Booking\Models\Booking;
use App\Domain\Catalog\Models\CourseSession;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Support\Ledger;
use App\Domain\Wallet\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * BR-06: give a freed seat to the earliest waitlisted student who can pay.
 * Caller holds the session row lock. A skipped student keeps their place (ERD comment).
 * The price is the session price at promotion (Q2), snapshotted with the commission (C11).
 */
final class WaitlistPromoter
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Dispatcher $events,
    ) {}

    public function promoteNext(CourseSession $session, CarbonImmutable $now): ?Booking
    {
        if (! $session->hasFreeSeat()) {
            return null;
        }

        $price = Money::of($session->price);

        $candidates = Booking::query()->waitlistQueue($session->id)->lockForUpdate()->get();

        // One unlocked read of every candidate's balance (no N+1), so wallets of students
        // who cannot pay are never locked: fewer cross-wallet locks, lower deadlock risk.
        $balances = Wallet::query()->whereIn('student_id', $candidates->pluck('student_id'))->pluck('balance', 'student_id');

        foreach ($candidates as $candidate) {
            $peek = $balances->get($candidate->student_id);
            $wallet = $peek !== null && ! Money::of($peek)->isLessThan($price)
                ? $this->lockWalletThatCanPay($candidate->student_id, $price)
                : null;

            if ($wallet === null) {
                // ShouldDispatchAfterCommit: the notification never fires for a rolled-back promotion.
                $this->events->dispatch(new WaitlistedStudentSkipped($candidate->id, $candidate->student_id, $session->id));

                continue;
            }

            $candidate->confirm($price, $session->center->commission_rate, Actor::System, $now);
            $session->takeSeat();
            $this->ledger->charge($wallet, $candidate, $price);

            return $candidate;
        }

        return null;
    }

    /** The peek was unlocked, so the balance is re-checked under the wallet lock before charging. */
    private function lockWalletThatCanPay(int $studentId, Money $price): ?Wallet
    {
        $wallet = Wallet::query()->where('student_id', $studentId)->lockForUpdate()->firstOrFail();

        return Money::of($wallet->balance)->isLessThan($price) ? null : $wallet;
    }
}
