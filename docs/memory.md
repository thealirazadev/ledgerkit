# Project Memory - ledgerkit

Running log of what is done, in progress, and decided. Update after every meaningful chunk of
work; log every non-obvious decision with its reason. Keep entries short and dated.

## Completed

- 2026-07-27 - Planning documentation created (README, PRD, architecture, rules, phases,
  design, testing, api-contracts, launch-checklist, memory). No code yet; docs await owner
  review before Phase 1 starts.

## Project status

- Planning stage. Implementation follows `docs/phases.md`, Phase 1 first, one phase at a time
  with owner approval between phases.

## Decisions log

- 2026-07-27 - Amounts are signed bigints, debit-positive, instead of an unsigned amount plus a
  debit/credit column. Reason: "balanced" collapses to exactly `SUM(amount) = 0` per currency,
  which a single deferred trigger and a single verify query can check, and there is no
  amount/side disagreement class of bugs. The debit/credit vocabulary survives in the API
  (`debit()`/`credit()`), so callers never touch raw signs.
- 2026-07-27 - `ledger_postings.id` is a bigint identity, not a ULID, while `ledger_entries.id`
  is a ULID. Reason: snapshots need a monotone, comparable boundary key for "snapshot plus tail
  equals full recomputation", and integer sequences give that cheaply; entries are the ids a
  host application will expose and store, where ULIDs are safer and sortable enough.
- 2026-07-27 - Posting-currency-equals-account-currency is enforced by a composite foreign key
  (`account_id, currency` referencing a unique `(id, currency)` on accounts) rather than a
  trigger. Reason: it works identically on both engines, costs nothing at read time, and
  removes an entire trigger from the parity surface; the redundant `currency` column on
  postings is the price and is also exactly what balance queries want to read.
- 2026-07-27 - The snapshot boundary excludes postings younger than a configurable safety
  window (default 300s) instead of using plain `MAX(id)`. Reason: sequence ids commit out of
  order; a transaction can hold id 100 uncommitted while id 101 commits, and a boundary of 101
  taken at that moment would omit posting 100 forever. The window is sound whenever no write
  transaction outlives it, which the deliberately two-statement posting transaction makes easy;
  `ledger:verify` exists to catch the assumption failing rather than trusting it.
- 2026-07-27 - The idempotency fingerprint covers only the economic lines (sorted
  account/amount/currency tuples), not description, actor, external_ref, or posted_at. Reason:
  a legitimate retry after a partial failure may re-derive timestamps and human metadata, and
  failing such a retry with a conflict would push callers toward removing idempotency keys; a
  change in the money itself is the thing that must conflict loudly.
