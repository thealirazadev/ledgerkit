# Project Memory - ledgerkit

Running log of what is done, in progress, and decided. Update after every meaningful chunk of
work; log every non-obvious decision with its reason. Keep entries short and dated.

## Completed

- 2026-07-27 - Planning documentation created (README, PRD, architecture, rules, phases,
  design, testing, api-contracts, launch-checklist, memory). No code yet; docs await owner
  review before Phase 1 starts.
- 2026-07-28 - Phase 1 implemented in the eight commits listed in `docs/phases.md`: composer
  package skeleton with the service provider and stub commands, config plus `.env.example`,
  migrations 0001-0004 (accounts with type and currency CHECKs and the unique `(id, currency)`;
  entries with the unique idempotency key and unique `reverses_entry_id`; postings with the
  composite currency FK and `CHECK amount <> 0`; balance snapshots), `AccountType` enum and
  `Account` model, `Ledger::createAccount()`/`Ledger::account()` with validation, the
  `LedgerException` base with `UnknownAccountException`, `InvalidAccountException` and
  `UnsupportedDriverException`, driver rejection in every migration, and the feature suite plus
  the two-engine CI matrix.

## Project status

- Phase 1 complete and verified locally; Phase 2 (balanced entries, immutability, reversal) is
  not started and waits on owner approval. Implementation follows `docs/phases.md`, one phase at
  a time.

## Verification log

- 2026-07-28 - Observed green: `vendor/bin/pest` 39 passed (69 assertions) against PostgreSQL
  15.18, PostgreSQL 16, and MySQL 8.4.10, and `vendor/bin/pint --test` clean on 22 files. The
  machine's own PHP is 8.2, so all runs happened inside a PHP 8.3 container built with
  `pdo_pgsql`, `pdo_mysql` and `bcmath`; `composer install` ran there too and `composer.lock` is
  committed.
- 2026-07-28 - Not verified: the GitHub Actions matrix has not been observed running (the
  workflow was pushed with this phase, no run watched), and the MySQL job in CI uses the
  `mysql:8` tag, which locally resolved to 8.4.10 rather than an 8.0.x server.

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
- 2026-07-28 - **Needs owner sign-off**: composer now blocks `laravel/framework` 11.x because
  the branch carries unpatched advisories (PKSA-m5cs-t1y6-qpcs, PKSA-3r5d-mb8f-1qw9,
  PKSA-mdq4-51ck-6kdq are all fixed only in 12.x), so `orchestra/testbench` ^9 could not resolve
  at all. To keep the `illuminate/* ^11` stack that `docs/architecture.md` fixes, those three ids
  are listed under `config.policy.advisories.ignore-id` in `composer.json`. They affect signed
  URL handling, the email validation rule, and file validation, none of which this package or its
  test harness uses, and `laravel/framework` is a dev-only dependency here (the runtime requires
  are the `illuminate/*` components). The alternative is moving the package to Laravel 12
  components, which would change `docs/architecture.md` and needs approval.
- 2026-07-28 - `ledger_entries.id` declares its primary key with `$table->primary('id')` instead
  of the `->primary()` column modifier. Reason: the fluent modifier is compiled after the foreign
  key commands, so the self-referencing FK on `reverses_entry_id` failed on PostgreSQL with "no
  unique constraint matching given keys"; the explicit call orders the primary key first.
- 2026-07-28 - The MySQL currency CHECK compares `CAST(currency AS BINARY)` with
  `CAST(UPPER(currency) AS BINARY)` on top of the regex. Reason: MySQL string comparison is case
  insensitive by default, so `REGEXP '^[A-Z]{3}$'` alone also accepts `usd`; PostgreSQL needs no
  such workaround and keeps the plain `~ '^[A-Z]{3}$'`.
- 2026-07-28 - Two test files were added that the architecture tree does not list:
  `tests/Feature/MigrationTest.php` (migrate, rollback, unsupported driver) and
  `tests/Feature/SchemaConstraintTest.php` (raw-SQL CHECK, composite FK, and unique index
  backstops). Reason: Phase 1's commit covers schema constraints, and folding them into
  `AccountTest.php` would have mixed the public API surface with the deliberate bypass tests.
- 2026-07-28 - The three artisan commands ship as stubs that print "not implemented yet" and exit
  1 until their phases land, rather than being registered later. Reason: Phase 1's definition of
  done requires the provider to register them, and a nonzero exit keeps an unimplemented command
  from ever looking like a successful run.
- 2026-07-27 - The idempotency fingerprint covers only the economic lines (sorted
  account/amount/currency tuples), not description, actor, external_ref, or posted_at. Reason:
  a legitimate retry after a partial failure may re-derive timestamps and human metadata, and
  failing such a retry with a conflict would push callers toward removing idempotency keys; a
  change in the money itself is the thing that must conflict loudly.
