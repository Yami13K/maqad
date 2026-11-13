# DECISIONS

## D1
I lock the session row. `LockOrder::session()` runs `SELECT ... FOR UPDATE` on `sessions` inside the booking transaction, then `CourseSession::hasFreeSeat()` compares `confirmed_count` with `capacity` and `takeSeat()` increments it. Every request for that session waits on that one row, so the check and the write happen as one step.
`CHECK (confirmed_count BETWEEN 0 AND capacity)` in migration 000002 is the backstop. It caps the counter, but it can't prove the counter equals the real confirmed rows. That part holds because every path that confirms, promotes or cancels updates both in the same transaction.
I rejected the Phase 2 approach of counting bookings with a lock. Postgres won't run `FOR UPDATE` on an aggregate at all. Separately, that code takes the lock before its transaction starts, so the lock is gone before the insert: two requests can both see 9 of 10 seats and end up with 11 bookings. SERIALIZABLE or optimistic version checks would also work, but they need retries when everyone hits the session at once, and one locked row is simpler.
T1 sends 50 parallel HTTP requests at 10 seats (`tests/Support/ParallelServer.php`) and checks for exactly 10 confirmed, 40 waitlisted and 10 charges.

## D2
The ledger (`wallet_transactions`) is the truth and `wallets.balance` is a cache. Opening balances are seeded as `topup` rows, so `balance = SUM(amount)` holds from the first row.
Booking charges and refunds go through `Ledger`. It inserts the signed row and updates `balance` in the same transaction, while the caller holds the wallet row `FOR UPDATE`. The seeder writes the opening top-ups on its own.
The database blocks UPDATE, DELETE and TRUNCATE on the ledger, checks the sign and `booking_id` for each type, allows one charge and one refund per booking, and rejects a negative balance. It does not check balance = sum across the two tables. That rests on the locking protocol, so a direct write that skips `Ledger` could drift.
`assertLedgerMatchesBalances()` in `tests/TestCase.php` recomputes balances from the ledger in selected tests, including T1, T2 and T4. It catches drift in tests, it doesn't prevent it at runtime.

## D3
Table `idempotency_keys` with `UNIQUE (student_id, idempotency_key)`. Each row keeps `request_hash` (sha256 of method, path and raw body) and the stored response status and body.
`IdempotencyGuard::claim()` runs `INSERT ... ON CONFLICT DO NOTHING` inside the booking transaction, so the key and the booking commit together or not at all. A parallel duplicate waits on the unique index until the first one commits, then gets the stored response back with `Idempotent-Replayed: true`. If the booking fails, the claim rolls back with it and a retry starts clean.
Same key with a different hash gets 422 `idempotency_key_reused` and nothing is written.
`routes/console.php` schedules an hourly prune of keys older than 24h, but compose doesn't start a scheduler, so nothing is pruned today. Once pruning runs, the protection ends with the row: a late retry after the student cancelled could book and charge again. Protecting forever means keeping the request identity instead of deleting it.
Tested by T3 (same key twice in a row) and the parallel T3 (same key at the same moment, one booking, one charge).

## D4
`sessions.starts_at` is `timestamptz`, the UTC instant of the branch's local start, and `branches.timezone` holds the IANA zone (`Asia/Damascus`, `Asia/Muscat`). I convert local time to an instant once, when the session is created.
After that, `RefundPolicy::percentFor()` only compares two instants, start and now. So 48h and 12h mean real elapsed time in any zone, and there is no offset math in the refund path.
Tiers: `remaining >= 48h` is 100%, `12h <= remaining < 48h` is 50% (Q4), and `remaining < 12h` is 0 with no ledger row. The 50% amount is rounded half-up to 3 decimals.
T4 runs 8 cases on a frozen clock: 48:01, 47:59, 12:00 and 11:59 before start, in both Damascus and Muscat.

## D5
```sql
-- Design only. Month follows Q6: :month is the first local day of the month, and :center_id must come from the manager's own scope. I count bookings that were ever confirmed, gross is charges minus refunds, and commission scales with what wasn't refunded. The existing sessions(branch_id, starts_at) and wallet_transactions(booking_id, type) indexes cover the lookups; the new covering index includes b.id for the refund join. Whether Postgres picks an index-only scan depends on the planner and the visibility map, so I'd confirm with EXPLAIN on 2M bookings.
WITH s AS (SELECT s.id, s.branch_id, s.capacity, s.confirmed_count FROM sessions s JOIN branches br ON br.id = s.branch_id WHERE s.center_id = :center_id AND s.starts_at >= (:month::timestamp AT TIME ZONE br.timezone) AND s.starts_at < ((:month::timestamp + interval '1 month') AT TIME ZONE br.timezone)), m AS (SELECT s.branch_id, COUNT(*) AS bookings, SUM(b.price_charged - COALESCE(r.amount, 0)) AS gross, ROUND(SUM(b.commission_amount * (b.price_charged - COALESCE(r.amount, 0)) / NULLIF(b.price_charged, 0)), 3) AS commission FROM s JOIN bookings b ON b.session_id = s.id AND b.price_charged IS NOT NULL LEFT JOIN wallet_transactions r ON r.booking_id = b.id AND r.type = 'refund' GROUP BY s.branch_id)
SELECT o.branch_id, COALESCE(m.bookings, 0) AS bookings, COALESCE(m.gross, 0) AS gross, COALESCE(m.commission, 0) AS commission, COALESCE(m.gross - m.commission, 0) AS net, ROUND(o.seats::numeric / o.capacity, 4) AS occupancy FROM (SELECT branch_id, SUM(confirmed_count) AS seats, SUM(capacity) AS capacity FROM s GROUP BY branch_id) o LEFT JOIN m USING (branch_id);
CREATE INDEX CONCURRENTLY bookings_report ON bookings (session_id) INCLUDE (id, price_charged, commission_amount) WHERE price_charged IS NOT NULL;
```

## D6
Design only. I'd run it in the background so one HTTP request isn't holding the session lock plus up to 800 wallet locks. That needs a queue worker, queue storage and a recovery scheduler, which this stack doesn't have yet.
Step one is a short transaction: lock the session, move it from `scheduled` to `cancelling`, set `cancelled_at`, dispatch the job after commit and return 202. Calling it again resumes the same run instead of restarting it.
While the session is `cancelling` or `cancelled`, student cancellation and waitlist promotion have to be refused under the session lock, same as new bookings. Booking already checks session state, but cancel and promotion don't yet, and without that guard a student could get a partial refund or a waitlisted student could get charged in the middle of the cancellation.
The worker (database queue) takes active bookings in chunks of about 100 by id, locks the session, then those bookings, then their wallets in ascending id order, and retries the whole chunk on deadlock. Under the lock it rechecks each booking, moves it to `cancelled_by_center` with an audit row, refunds confirmed ones 100% through `Ledger::refund()` and decrements `confirmed_count` once per confirmed booking. Waitlisted ones move no money, and earlier student cancellations stay final (Q5).
Each chunk's status, counter, ledger and balance writes commit or roll back together. A restart only picks bookings that are still active, and the unique `(booking_id, type)` index rejects a duplicate refund anyway, so a uniqueness error must roll back the whole chunk, never get swallowed.
The session becomes `cancelled` only when, under its lock, no active bookings are left and `confirmed_count = 0`. A sweep re-dispatches sessions stuck in `cancelling`, which also covers a crash before the first dispatch, and notifications go out after commit.
