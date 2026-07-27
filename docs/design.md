# Design - ledgerkit

ledgerkit has no UI; its "design surface" is the PHP API a developer reads in their editor and
the output of three artisan commands read in a terminal. Both follow the same principles: the
happy path reads like the sentence describing it, errors name the exact invariant violated, and
nothing is ambiguous about direction or sign.

## API ergonomics

- **Fluent draft, explicit direction**: `debit()` and `credit()` are the vocabulary, never raw
  signs. The signed storage convention (debit-positive) is internal; a caller who has never met
  double-entry can still read `->debit($cash, 500)->credit($revenue, 500)` correctly.
- **One result object**: every posting call returns `PostedEntry` exposing `entry`, `postings`,
  and `replayed` (bool). No mixed return types, no null-on-duplicate.
- **Amounts read as money**: integer minor units, or decimal strings (`'12.50'`) converted
  exactly; passing a float throws immediately with a message that says why floats are banned.
- **Exceptions are the contract**: each failure mode has a named exception with a stable
  `errorCode()` (table in `docs/api-contracts.md`); a caller can catch the base
  `LedgerException` or a precise one. Messages are one sentence, no SQL fragments.

## Command UX

Shared conventions for all `ledger:*` commands:

- Plain text output, one fact per line, aligned columns for tables; no spinners, no color
  requirements (color via Laravel's default styling only, information never conveyed by color
  alone).
- Exit codes: 0 success, 1 invariant/verification failure, 2 usage error (bad flag, missing
  account). Library errors print as `error [<code>]: <message>` on stderr.
- Every command names the connection and database it is about to touch on its first line, so
  running against the wrong environment is visible before it matters.

### ledger:verify

```
$ php artisan ledger:verify
connection: pgsql (app_production)
entries checked:    12480 .. ok (zero-sum, 2+ postings, posted_at agreement)
snapshots checked:     14 .. ok (balance and count match full recomputation)
verify: clean
```

Failure output lists each violation with ids and both values (`stored` vs `recomputed`), then
exits 1. Read-only; safe on production. `--account=` limits the snapshot pass to one account.

### ledger:snapshot

```
$ php artisan ledger:snapshot
connection: pgsql (app_production)
cash.main        boundary 48211  balance 1204500  (tail was 3120 postings)
fees.stripe      skipped (tail 240 < min_tail 1000)
snapshots written: 1
```

Flags: `--account=<code>` one account; `--force` ignores `min_tail`. Idempotent: a re-run with
no new postings past the window writes nothing and says so.

### ledger:demo

Runs the full scripted scenario. Because it writes real rows, it opens with the connection name
and a confirmation prompt (`--yes` skips it, for CI):

```
$ php artisan ledger:demo
connection: pgsql (scratch)
This will write demo.* accounts and entries to the database above. Continue? (yes/no)
created accounts: demo.cash (asset USD), demo.revenue (income USD), demo.fees (expense USD)
posted  entry 01J...A  invoice paid          demo.cash +10000 / demo.revenue -10000
posted  entry 01J...B  card fee              demo.fees +290   / demo.cash -290
retry   entry 01J...A  replayed=true (same idempotency key, no double post)
reversed entry 01J...B with 01J...C
balances: demo.cash 10000   demo.revenue -10000   demo.fees 0
verify: clean
```

Each step prints what a reader should notice (the replay flag, the zeroed reversal); the final
balance line is hand-checkable from the lines above it.

## Error and empty states

- Empty ledger: `verify` and `snapshot` succeed with explicit "nothing to check/write" lines,
  never silent exits.
- Wrong driver: the migration failure message names the driver found, the drivers supported,
  and that nothing was applied.
- Guard rejection in `transferGuarded`: whatever the guard closure throws propagates unchanged
  after rollback, so the caller's own domain error (e.g. `InsufficientFundsException`) is what
  their code sees; ledgerkit does not invent a wrapper.

## Documentation surface

The README quickstart must fit on one screen: install, migrate, create two accounts, post one
entry, read one balance. Every public method gets a doc block whose first line is usable as a
tooltip; the cooperative-lock caveat on `transferGuarded` and the safety-window assumption on
`SnapshotBuilder` are documented at the call site, not only in `docs/`.
