## A. Bugs
| ID | Line(s) | Bug | Timeline scenario (t1, t2, ...) | Visible locally with 1 request? (yes/no + why) |
| --- | --- | --- | --- | --- |
| B1 | 17-20 | `lockForUpdate()->count()` turns into `SELECT count(*) ... FOR UPDATE`. PostgreSQL refuses that, so on PostgreSQL every single booking fails. MySQL accepts it, which explains why it looked fine in a MySQL dev setup. | t1: request A for session 7 runs `select count(*) as aggregate from bookings where session_id = 7 and status = 'confirmed' for update`. t2: PostgreSQL returns SQLSTATE 0A000 "FOR UPDATE is not allowed with aggregate functions". t3: Laravel throws `QueryException` and A gets a 500. t4: the next 1,000 requests fail the same way, 0 bookings go through. | Yes on PostgreSQL: the very first request fails, I reproduced it. No on MySQL, which accepts the statement. |
| B2 | 17-20, 33 | The locking read happens before `DB::beginTransaction()` on line 33, so it runs in autocommit and the lock is gone as soon as the statement finishes. The check and the insert are not atomic. | Capacity 10, 9 confirmed. t1: A counts 9, lock released. t2: B counts 9, lock released. t3: A checks `9 >= 10`, false, inserts: 10 confirmed. t4: B passes the same stale check and inserts: 11 confirmed for 10 seats. I reproduced this on MySQL 8.0.46 with the exact code: 50 parallel requests on an empty 10-seat session gave 26 confirmed, and 9 confirmed plus 2 parallel requests ended at 11 in 4 of 20 rounds. | No: one request can't interleave with itself, so the check is always right when requests come one by one. |
| B3 | 17-20 | Moving `beginTransaction()` above the count doesn't fix it on PostgreSQL. The aggregate form is rejected (B1), and the closest version that runs, `lockForUpdate()->get()` and counting in PHP, only locks rows that already exist. An unbooked seat has no row to lock (phantom row), so nothing stops two inserts. | Capacity 10, 0 confirmed, 50 requests at once. t1: A selects confirmed rows FOR UPDATE, gets 0 rows, locks nothing. t2: B gets 0 rows, locks nothing, nothing blocks. t3: both see 0 < 10 and insert. On PostgreSQL I got 47 confirmed for 10 seats. On MySQL 8.0.46 the same change held capacity in my runs, but I haven't established why, so I don't treat it as a guarantee. Under REPEATABLE READ one of the 50 requests also hit deadlock 40001 and, with no retry, returned 500. | No: needs at least 2 concurrent transactions. |
| B4 | 26, 29, 41-42 | The wallet is read without a lock, the new balance is computed in PHP from that stale copy, then saved over whatever is there. Classic lost update. | Balance 100, session X costs 50, session Y costs 65.5. t1: A (X) reads 100. t2: B (Y) reads 100. t3: both pass the balance check. t4: B writes 100 - 65.5 = 34.5. t5: A writes 100 - 50 = 50. End result: 2 seats worth 115.5, balance 50, B's charge is gone. I reproduced it on MySQL with the exact code: balance 60, two 50 seats in parallel, both confirmed in 11 of 20 rounds, ending at balance 10. | No: one after the other, the second read sees the first write and fails with "Insufficient balance". |
| B5 | 41-42 | Money moves by editing `wallets.balance` directly, with no `wallet_transactions` row. The balance can't be rebuilt from the ledger (BR-05) and a refund has nothing to point to. | t1: balance 1000, ledger sum 1000. t2: book a 50 seat: balance 950, no ledger row. t3: `SUM(amount)` is still 1000, so the cache and the ledger are 50 apart. t4: after a B4 race nobody can tell which charge was lost. | Yes: one booking leaves 0 ledger rows, I reproduced it. |
| B6 | 27 | The price comes from the course, but it belongs to the session and differs by branch (BR-03). Nothing is snapshotted on the booking either, neither price nor commission (BR-09). | Course price 50, Muscat session priced 65.5. t1: a student books the Muscat session. t2: they're charged 50, the center loses 15.5 per seat. t3: next month the center's commission goes from 10% to 15%. t4: the monthly report recomputes this booking at 15% because nothing was frozen at confirmation. | Yes: one booking on a session priced differently from its course shows the wrong charge. The given ERD has no session price at all; I checked, `sessions` only has id, course_id, branch_id, starts_at, capacity. |
| B7 | 26, 29 | `Wallet::...->first()` returns `null` when the student has no wallet, and then `$wallet->balance` reads a property on null. | Student 61 has no wallet row. t1: line 26 returns null. t2: line 29 reads `null->balance` and Laravel turns the PHP warning into `ErrorException`. t3: the client gets a 500 instead of a clear 404 or 422. | Yes: one request for a student without a wallet. I got `ErrorException: Attempt to read property "balance" on null`. |
| B8 | 33-46 | Manual `beginTransaction()` and `commit()` with no `try/catch` and no `rollBack()`. Any exception between 33 and 46 leaves the transaction open on that connection, and deadlocks are never retried. | t1: line 33 opens a transaction (level 1). t2: line 35 inserts booking 501. t3: line 42 fails (deadlock 40P01 or a constraint). t4: line 46 never runs and nothing rolls back. Under php-fpm the connection closes at the end of the request and the database rolls back, but in a long-lived process (queue worker, Octane) it gets worse. t5: the next call's `beginTransaction()` becomes a savepoint at level 2, its line 46 only drops Laravel's nesting counter back to 1 without a real COMMIT, and booking 502 is never committed. On PostgreSQL every later statement fails with "current transaction is aborted". | Yes for the missing rollback: one request that fails at line 42 (a constraint or a forced error) leaves the transaction open. The lost booking only shows on a second call in the same long-lived process. I reproduced it on MySQL with a forced failure at line 42 and a second call in the same process: level stayed at 1, the second booking was invisible to other connections and disappeared when the connection closed. |
| B9 | 44 | The job is dispatched inside the transaction, before `commit()`. Redis hands it to the worker right away, before the booking row is visible, and it's still sent if the transaction later rolls back. | t1: line 35 inserts booking 501, not committed yet. t2: line 44 pushes the job to Redis. t3: the worker picks it up 1 ms later and reloads Booking 501 (`SerializesModels`): not visible yet, `ModelNotFoundException`, job fails. t4: line 46 commits and the student never gets the email. Other way round: if t4's commit fails, the email confirms a booking that doesn't exist. | Yes with an async worker: one request plus a Redis worker is enough. With a real Redis worker 40 bookings committed and 23 of their 40 confirmation jobs failed with `ModelNotFoundException`. No with the usual local `QUEUE_CONNECTION=sync`, which runs the job inline inside the same transaction, where row 501 is visible. |
| B10 | 22-24, 29-31 | A plain `\Exception` for both "full" and "insufficient", so both end up as a 500. A full session should put the student on the waitlist (BR-06, 202), and low balance is a 402. | Capacity 10, 10 confirmed. t1: student 11 books. t2: line 23 throws `Exception('Session is full')`. t3: 500, no waitlist row, so this student can never be promoted. t4: the mobile app treats 5xx as temporary and retries 3 times: 3 more 500s. | Yes: one request on a full session, or one with balance 40 for a 50 seat. |
| B11 | 15-46 | No duplicate check and no time-overlap check (BR-07). Even if there were one, it would race without a per-student lock. | Balance 100, session S1 10:00-12:00 for 40, S2 11:00-13:00 for 40. t1: the student books S1: confirmed, balance 60. t2: books S2: confirmed, balance 20. Two overlapping seats, 80 charged. In parallel: t1: A (S1) sees no overlap, t2: B (S2) sees no overlap, t3: both insert. | Yes: one new request shows it when the student already holds an overlapping booking. The parallel version needs 2 concurrent requests. |
| B12 | 13 | No idempotency (BR-11). A retried request is treated as a brand new booking. | Balance 100, price 40. t1: request A commits booking 501, balance 60. t2: the response is lost on a weak network. t3: the app sends the same request again. t4: without a unique constraint: booking 502, balance 20, charged twice. With the given ERD's `UNIQUE(student_id, session_id)`, t4 fails with a duplicate key 500 instead, so the app shows an error for a booking that actually went through. | No: needs a lost response and a resend. |
| B13 | 15, 22 | The session is loaded without a lock and its state is never checked, so a cancelled or already started session can still be booked. | t1 09:00: the center cancels session 7 (status `cancelled`) and the refund run for its 10 bookings starts. t2 09:01: student 61 books session 7: line 15 loads it, line 22 sees 0 confirmed < 10, line 35 confirms, line 42 charges 50. t3: the refund run has already passed, so student 61 paid 50 for a cancelled session. Same story for a session that started at 08:00. | Yes: one request on a cancelled or started session gets accepted. I booked a session that had started an hour earlier. The given ERD can't even store `cancelled` for a session. |

## B. Fixed code
(same language and framework: PHP + Laravel)

I wrote the fix against my own schema so it actually runs: `sessions.price`, `sessions.confirmed_count` with `CHECK (confirmed_count BETWEEN 0 AND capacity)`, the `wallet_transactions` ledger and `idempotency_keys`. That is why it imports `App\Domain\...` instead of the snippet's `App\Models\...`. It is the same algorithm as my booking endpoint (`BookSeatAction`, E1).

Three things changed on purpose:

- `bookSeat()` now takes the same `BookSeatData` as my endpoint and returns a `BookingResult`. B12 cannot be fixed without an idempotency key, and reusing the endpoint's DTO and `IdempotencyGuard` means one key format (1 to 255 characters, otherwise 422), one request fingerprint and one stored response. A retry that hits the service after the API, or the other way round, gets the original response replayed.
- `App\Jobs\SendBookingConfirmation` is the job from the original app. My repo has no such job, so I tested with a stub that uses `SerializesModels`, like a real queued job.
- Dispatching after commit fixes the ordering, not delivery. If the dispatch itself fails after commit, nothing retries it. That needs an outbox, which is out of scope here.

How I checked it: PostgreSQL 16, a real Redis queue worker, one check per bug, parallel cases as separate OS processes. 32 of 32 pass. B1 runs with no 0A000. B2: 9 confirmed plus 2 parallel gives 1 confirmed and 1 waitlisted. B3: 50 parallel on 10 seats gives 10 confirmed, 40 waitlisted, 10 charges. B4: balance 100 with 50 and 65.5 in parallel gives 1 confirmed and 1 402. B5: every positive-price confirmed booking has exactly one matching charge row (free ones have none) and every balance equals `SUM(amount)`. B6: session price and frozen commission. B7: 404 instead of `ErrorException`. B8: a failure injected on the ledger insert leaves transaction level 0 with nothing saved, and the next call in the same process commits. B9: every confirmed booking's job ran on the Redis worker, 0 failed. B10: 202 and 402. B11: sequential and parallel overlaps and duplicates rejected. B12: 5 parallel sends of one key give one booking, one charge, one job; a booking made through the service and then retried over HTTP with the same key comes back as a replay with the same `booking_id`. B13: cancelled and started sessions rejected. Also: an empty key returns 422, and a free session (price 0) confirms with no ledger row.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Data\BookSeatData;
use App\Domain\Booking\Enums\Actor;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\AlreadyBooked;
use App\Domain\Booking\Exceptions\InsufficientBalance;
use App\Domain\Booking\Exceptions\SessionNotBookable;
use App\Domain\Booking\Exceptions\TimeOverlap;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Support\IdempotencyGuard;
use App\Domain\Catalog\Models\CourseSession;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Support\Money;
use App\Jobs\SendBookingConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BookingService
{
    /** Deadlock (40P01) and serialization (40001) failures are retried by Laravel. */
    private const ATTEMPTS = 3;

    public function __construct(private readonly IdempotencyGuard $idempotency) {}

    /**
     * B12: takes the same input as the HTTP endpoint. BookSeatData validates the key (1 to 255
     * characters, else 422) and carries the endpoint's request fingerprint, sha256 of
     * "METHOD path body", so both writers share one idempotency protocol and one table.
     */
    public function bookSeat(BookSeatData $data): BookingResult
    {
        $created = null;

        // B8: DB::transaction() commits on success, rolls back on ANY exception, retries deadlocks.
        $result = DB::transaction(function () use ($data, &$created): BookingResult {
            $created = null; // reset on a deadlock retry

            // B12: claim the key in the same transaction; a duplicate waits on the unique index,
            // then gets the stored response replayed byte for byte.
            $replay = $this->idempotency->claim($data->studentId, $data->idempotencyKey, $data->requestHash);

            if ($replay !== null) {
                return $replay;
            }

            [$created, $result] = $this->book($data->studentId, $data->sessionId);
            $this->idempotency->complete($data->studentId, $data->idempotencyKey, $result);

            return $result;
        }, self::ATTEMPTS);

        // B9: queued only after the commit, so a worker can never read an uncommitted booking.
        // A replayed request (B12) sends nothing. This fixes ordering, not delivery: a failed
        // dispatch after commit is not retried here (that needs an outbox).
        if ($created !== null && $created->status === BookingStatus::Confirmed) {
            SendBookingConfirmation::dispatch($created)->afterCommit();
        }

        return $result;
    }

    /** @return array{0: Booking, 1: BookingResult} */
    private function book(int $studentId, int $sessionId): array
    {
        $now = CarbonImmutable::now();

        // B1, B2, B3: lock the ONE session row. Every booking of this session queues here, so the
        // seat check below and the counter write are atomic. Replaces COUNT(*) ... FOR UPDATE.
        $session = CourseSession::query()->with('center')->lockForUpdate()->findOrFail($sessionId);

        if (! $session->isBookableAt($now)) { // B13: cancelled or started sessions
            throw new SessionNotBookable;
        }

        // B4, B11: lock the wallet row (session first, then wallet: same order as cancel). All
        // requests of this student queue here, so balance and overlap checks cannot race.
        // B7: firstOrFail() throws ModelNotFoundException (404) instead of returning null.
        $wallet = Wallet::query()->where('student_id', $studentId)->lockForUpdate()->firstOrFail();

        $active = Booking::query()->where('bookings.student_id', $studentId)
            ->whereIn('bookings.status', [BookingStatus::Confirmed->value, BookingStatus::Waitlisted->value]);

        if ((clone $active)->where('bookings.session_id', $session->id)->exists()) {
            throw new AlreadyBooked;
        }

        $overlaps = (clone $active)
            ->join('sessions', 'sessions.id', '=', 'bookings.session_id')
            ->where('sessions.starts_at', '<', $session->ends_at)
            ->where('sessions.ends_at', '>', $session->starts_at)
            ->exists();

        if ($overlaps) {
            throw new TimeOverlap;
        }

        $booking = new Booking(['student_id' => $studentId, 'session_id' => $session->id]);

        if ($session->confirmed_count >= $session->capacity) {
            // B10: a full session is a waitlist entry (202), not an error. No charge until promotion.
            $booking->joinWaitlist($now);
        } else {
            // B6: the price belongs to the session (BR-03); commission is frozen now (BR-09).
            $price = Money::of($session->price);

            if (Money::of($wallet->balance)->isLessThan($price)) {
                throw new InsufficientBalance; // B10: 402, not 500
            }

            $booking->confirm($price, $session->center->commission_rate, Actor::Student, $now);
            $session->increment('confirmed_count'); // CHECK (confirmed_count <= capacity) backstops this

            // B5: append the ledger row, then move the cached balance in the same transaction.
            // A free session (price 0) moves no money, so it writes no ledger row.
            if ($price->isPositive()) {
                WalletTransaction::query()->create([
                    'wallet_id' => $wallet->id,
                    'booking_id' => $booking->id,
                    'type' => TransactionType::Charge,
                    'amount' => $price->negated()->toDecimal(),
                ]);
                $wallet->balance = Money::of($wallet->balance)->minus($price)->toDecimal();
                $wallet->save();
            }
        }

        $confirmed = $booking->status === BookingStatus::Confirmed;

        return [$booking, new BookingResult(
            bookingId: $booking->id,
            status: $booking->status,
            chargedAmount: $confirmed ? Money::of($session->price) : Money::zero(),
            refundedAmount: Money::zero(),
            promotedBookingId: null,
            walletBalance: Money::of($wallet->balance),
            httpStatus: $confirmed ? 201 : 202,
        )];
    }
}
```
