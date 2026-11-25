# Design decisions

This file records the engineering decisions behind ledgerkit that the code in this repository
already reflects, with the reason for each and the bugs or edge cases that shaped them. The
remaining parts of the design (posting transaction, triggers, idempotent replay, balances,
snapshots, commands) are specified in `docs/architecture.md` and `docs/api-contracts.md`.

## 1. Amounts are signed integers, debit positive

`ledger_postings.amount` is a signed `bigint` in minor units. A debit is stored positive and a
credit negative, instead of an unsigned amount plus a side column. With this convention
"balanced" is exactly `SUM(amount) = 0` per currency, which one query can check, and there is no
class of bug where the amount and the side disagree. Callers never touch raw signs:
`EntryDraft::debit()` and `credit()` apply a `+1` or `-1` sign to the line, so a reversal is a
plain negation of every line.

## 2. Posting currency is enforced by a composite foreign key

`ledger_accounts` carries a unique `(id, currency)` index whose only purpose is to be the target
of the foreign key `(account_id, currency)` on `ledger_postings`. That makes "a posting's
currency equals its account's currency" a database guarantee on both PostgreSQL and MySQL
without a trigger, at no read-time cost. The redundant `currency` column on postings is the
price, and it is also the column balance queries want to read. `EntryDraft` takes each posting's
currency from the resolved account, so the library path can never produce a mismatch, and
`SchemaConstraintTest` proves a raw insert in the wrong currency is rejected.

## 3. Entry ids are ULIDs, posting ids are an integer sequence

`ledger_entries.id` is a 26-character ULID generated in `EntryDraft::compile()`. Entries are what
a host application exposes and stores, and ULIDs are safe to expose and still sortable.
`ledger_postings.id` is a `bigint` identity instead, because the snapshot table needs a monotone,
comparable boundary key: `ledger_balance_snapshots.last_posting_id` is an inclusive boundary, and
"snapshot plus postings with a higher id equals full recomputation" only works with an ordered
integer key. Snapshots are append-only; the unique `(account_id, last_posting_id)` index makes a
rebuild at the same boundary a no-op, and `posting_count` lets a verifier cross-check a snapshot
cheaply.

## 4. Constraints live in the database, not only in PHP

Every rule PHP validates also has a database backstop, so a writer that bypasses the library
cannot corrupt the books: a CHECK that `amount <> 0`, a CHECK on account `type` membership, a
CHECK on the currency format, unique `code`, unique `idempotency_key`, and unique
`reverses_entry_id`. `tests/Feature/SchemaConstraintTest.php` deliberately inserts raw rows to
prove each backstop fires.

Edge case found on MySQL: string comparison there is case insensitive by default, so
`REGEXP '^[A-Z]{3}$'` alone also accepts `usd`. The MySQL CHECK therefore adds
`CAST(currency AS BINARY) = CAST(UPPER(currency) AS BINARY)`. PostgreSQL keeps the plain
`~ '^[A-Z]{3}$'`.

## 5. Unique indexes decide races, not reads

Where two concurrent callers could both pass a "does it exist yet" check, the unique index is
the arbiter. `LedgerService::createAccount()` inserts directly and translates a unique violation
(SQLSTATE `23505` on PostgreSQL, error `1062` on MySQL) into `InvalidAccountException`; any other
`QueryException` is re-thrown untouched. The same reasoning puts unique indexes on
`idempotency_key` (at most one entry per key) and `reverses_entry_id` (at most one reversal per
entry), so neither guarantee depends on a read racing a write.

## 6. Only PostgreSQL and MySQL are supported

Every migration calls a driver guard first and throws `UnsupportedDriverException`
(`unsupported_driver`) for anything other than `pgsql` or `mysql`, before any DDL runs, so
nothing half-applies. SQLite in particular is refused because the correctness story relies on
triggers and row locks it cannot express, and quietly running there would ship a weaker ledger.
Driver-specific SQL (the currency CHECK) sits inside the migrations behind an explicit `match`
on the driver name.

## 7. Primary key declared before the self-referencing foreign key

`ledger_entries.reverses_entry_id` references `ledger_entries.id`. Declaring the primary key with
the `->primary()` column modifier failed on PostgreSQL with "no unique constraint matching given
keys", because the schema builder compiles that modifier after the foreign key commands. The
migration calls `$table->primary('id')` explicitly right after the column, which orders the
primary key first on both engines.

## 8. Money input is integers or exact decimal strings, never floats

An amount is either `int` minor units or a decimal string such as `'12.50'`. A float is accepted
by the method signature only so it can be rejected with `invalid_amount` and a clear message
instead of a `TypeError`. Decimal strings are converted once, with bcmath, against the currency's
ISO 4217 minor-unit exponent (a short table of non-2 exponents, defaulting to 2). Sub-minor
precision is refused rather than rounded, since it is a caller bug. Edge cases handled along the
way:

- The magnitude is bounded by `PHP_INT_MAX` rather than `PHP_INT_MIN`, so negating a line for
  the credit side or for a reversal can never overflow.
- Per-currency sums use `bcadd` instead of `+=`, because adding two large int64 amounts can
  leave PHP's integer range and silently become a float.
- Zero amounts are rejected, matching the database CHECK.

## 9. The entry draft is immutable and validates before any SQL

`EntryDraft` is a readonly builder: every mutator returns a new draft, so a half-built draft can
be shared or reused safely. `compile()` performs all PHP-side checks (at least two lines,
amounts, account resolution, per-currency zero sums, metadata lengths) and returns the exact rows
to insert, with no write SQL run, so a rejected draft persists nothing. Account codes are
resolved in a single `whereIn` query, and an `Account` model passed in directly is only trusted
if it is a persisted model (`exists`), so an unsaved instance cannot slip through. The entry's `posted_at` is copied onto every posting row so
as-of queries can read postings alone.

## 10. One exception hierarchy with stable codes and safe context

Every library error extends `LedgerException`, which exposes a stable `errorCode()` and a
`context()` array. Context carries ids, codes and reasons only, never full posting lines, so
exception payloads are safe to log in a host application. A draft with fewer than two lines and
one whose sums do not reach zero both report `unbalanced_entry`; metadata that is empty or wider
than its column reports `invalid_entry`.

## 11. Tests run against real engines

There is no SQLite stand-in in the test suite. `tests/TestCase.php` boots orchestra/testbench on
PostgreSQL or MySQL (chosen by `LEDGERKIT_TEST_DB`) and runs `migrate:fresh` per test, because the
behaviour under test lives in CHECK constraints, foreign keys and unique indexes that an
in-memory database would not reproduce. A dedicated `sqlite_unsupported` connection exists only
so the driver guard has something to refuse.

## 12. Dev-only advisory ignores for the Laravel 11 test harness

The runtime requirements are pinned `illuminate/*` 11.x components. `orchestra/testbench` 9 pulls
in `laravel/framework` 11.x as a dev dependency, and composer blocks that branch for three
advisories that are fixed only in 12.x (signed URL handling, the email validation rule and file
validation). None of those code paths are used by the package or its tests, so the three ids are
listed under `config.policy.advisories.ignore-id` in `composer.json`. Moving the package to
Laravel 12 components is the alternative that would remove the ignores.
