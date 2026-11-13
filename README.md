# Maqad

Run:

```
docker compose up --build
```

Test:

```
docker compose exec app php artisan test
```

## Findings

- A session priced 0 is allowed by the schema (`CHECK (price >= 0)`), but the ledger CHECK requires a charge to be negative, so booking a free session failed on that CHECK. `Ledger::charge()` now skips the ledger row for a zero price, the same way `refund()` skips a zero refund. Covered by `a_free_session_is_confirmed_without_a_ledger_row`.
