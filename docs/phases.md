# Phases - ledgerkit

**Rule: phase N+1 does not start until the owner approves phase N.** Phases are ordered
smallest-useful-shippable first; each ends green (tests pass on both engines, Pint clean). One
commit per feature/task, Conventional Commits, in the listed order.

The senior differentiators are hard requirements placed early: signed-integer amounts, the
composite currency FK, and CHECK constraints land in Phase 1; the zero-sum invariant with
database enforcement (Postgres deferred constraint trigger) and immutability triggers land in
Phase 2; race-proof idempotency and the locking strategy land in Phase 3. None of these may slip
to a later phase.

---

## Phase 1 - Package skeleton, schema, and accounts

**Goal**: The package installs into a testbench app, migrates a constraint-hardened schema on
both engines, and creates/fetches accounts. Smallest slice that already carries the data-model
guarantees.

### Definition of done

- Composer package `thealirazadev/ledgerkit` with pinned `illuminate/*` ^11 components,
  `ext-bcmath`, PHP 8.3; service provider registers config, migrations, and (stub) commands;
  testbench boots it in CI against real Postgres and MySQL.
- Migrations 0001-0004 create `ledger_accounts`, `ledger_entries`, `ledger_postings`,
  `ledger_balance_snapshots` exactly as specified in `docs/architecture.md`, including the
  unique `(id, currency)` on accounts, the composite FK from postings, `CHECK amount <> 0`,
  the currency-format and type CHECKs, and the unique indexes on `idempotency_key` and
  `reverses_entry_id`. Running them on SQLite throws `UnsupportedDriverException` with a clear
  message and applies nothing.
- `AccountType` enum; `Account` model; `Ledger::createAccount(code, name, type, currency)` and
  `Ledger::account(code)` with validation (code format and length, currency `^[A-Z]{3}$`,
  duplicate code raises a clean error).
- `LedgerException` base with `errorCode()`; `UnknownAccountException`,
  `UnsupportedDriverException` wired.
- Pint clean; feature tests: migrations up/down on both engines, account CRUD validation,
  duplicate code, SQLite rejection; CI matrix runs the suite on pgsql and mysql.

### Verification

- Fresh testbench app migrates cleanly on Postgres 15 and MySQL 8; `migrate:rollback` drops
  everything including triggers-to-come placeholders (no orphaned objects).
- Unhappy paths: lowercase or 2-letter currency rejected in PHP and by the CHECK if inserted
  raw; a raw posting insert in a wrong currency fails on the composite FK on both engines;
  `amount = 0` raw insert fails the CHECK.
- `composer.lock` committed; `vendor/bin/pest` green on both engines; `vendor/bin/pint --test`
  clean.

### Commits

1. `chore: scaffold composer package with service provider`
2. `chore: add config and env example for test databases`
3. `feat: add ledger accounts migration with checks`
4. `feat: add entries postings and snapshots migrations`
5. `feat: add account type enum and account model`
6. `feat: add create account and account lookup api`
7. `feat: reject unsupported database drivers in migrations`
8. `test: cover schema constraints and account api on both engines`

---

## Phase 2 - Balanced entries, immutability, and reversal

**Goal**: Money moves. Entries with 2+ postings post atomically, unbalanced writes are impossible
(database-enforced on Postgres), nothing money-bearing can be updated or deleted, and corrections
are reversing entries.

### Definition of done

- `EntryDraft` fluent builder (`Ledger::entry()->debit($account, $amount)->credit(...)
  ->describe(...)->externalRef(...)->actor(...)->postedAt(...)->post()`) with all PHP-side
  validation: >= 2 lines, nonzero integers, decimal-string conversion via bcmath (floats
  rejected), account resolution in one query, per-currency zero sums.
- Posting transaction: entry insert + one multi-row postings insert, nothing else; `posted_at`
  denormalized onto postings; `PostedEntry` result object returned.
- Migration 0005 adds triggers: immutability (`BEFORE UPDATE/DELETE` raise) on entries and
  postings for both engines; the deferred `CONSTRAINT TRIGGER` balance check on Postgres.
  `Entry`/`Posting` models also guard `update`/`delete` in PHP with
  `ImmutableRecordException`.
- `Ledger::reverse($entry, ...)` posts the negated entry with `reverses_entry_id` set; double
  reversal (sequential and concurrent) raises `AlreadyReversedException` off the unique index.
- Structured logs: `ledger.entry_posted`, `ledger.entry_reversed`.
- Pint clean; tests: balanced single- and multi-currency entries, unbalanced/one-line/zero/float
  rejection with nothing persisted, raw-SQL unbalanced bypass aborted at commit (pgsql-only
  test), raw UPDATE/DELETE rejected by triggers on both engines, reversal and double-reversal
  race via a second connection.

### Verification

- On Postgres, `psql` by hand: inserting one unbalanced posting inside a transaction and
  committing aborts the commit; inside the library the same attempt never reaches SQL.
- Unhappy paths: draft against a missing account code fails before SQL; a 3-line entry balanced
  overall but unbalanced per currency is rejected; killing the process between entry insert and
  postings insert leaves no partial entry (single transaction, verified by test with a forced
  exception).
- Both engines green; MySQL run confirms immutability triggers fire and documents (in test
  names) that commit-time balance enforcement is pgsql-only.

### Commits

1. `feat: add entry draft builder with validation`
2. `feat: add atomic posting transaction with result object`
3. `feat: add immutability triggers and model guards`
4. `feat: add deferred balance constraint trigger on postgres`
5. `feat: add entry reversal with single reversal invariant`
6. `feat: add structured logging for posting and reversal`
7. `test: cover posting invariants immutability and reversal races`

---

## Phase 3 - Idempotency and concurrency

**Goal**: Retried and concurrent callers cannot double-post, and check-then-post flows have a
documented, tested locking strategy.

### Definition of done

- `withIdempotencyKey($key)` on the draft; `IdempotencyFingerprint` canonicalizes sorted
  `account_id:amount:currency` lines and hashes sha256; fingerprint stored with the entry.
- Unique-violation handling in the posting path: catch 23505/1062 on `idempotency_key` only,
  re-fetch, compare fingerprints; match returns the original as `replayed = true`, mismatch
  raises `IdempotencyConflictException`. All other violations re-raise untouched.
- `Ledger::transfer($from, $to, $amountMinor, ...)` two-line helper; cross-currency raises
  `CurrencyMismatchException`.
- `Ledger::transferGuarded($from, $to, $amountMinor, $guard, ...)`: transaction, `FOR UPDATE`
  on the source account row, full balance computed under the lock, guard closure decides,
  then the normal posting path. Cooperative-lock semantics documented in the method doc block
  exactly as in `docs/architecture.md`.
- Structured logs: `ledger.entry_replayed`, `ledger.idempotency_conflict`.
- Pint clean; tests: sequential replay, concurrent same-key race via a second database
  connection (both callers end with the same entry id, exactly one entry row), same key with
  different lines conflicts, transfer happy path and currency mismatch, two concurrent guarded
  transfers serialize and the second guard sees the first's debit.

### Verification

- Race tests run on both engines (second-connection technique, no sleep-based flakiness:
  block on the lock itself).
- Unhappy paths: idempotency key at the 191-char limit accepted, over it rejected in PHP;
  fingerprint comparison is order-insensitive (same lines in different order replay, not
  conflict); guard exception rolls back the whole transaction leaving no entry.

### Commits

1. `feat: add idempotency key and fingerprint to entry draft`
2. `feat: handle idempotency unique race with replay and conflict`
3. `feat: add transfer helper`
4. `feat: add guarded transfer with account row lock`
5. `test: cover idempotency races and guarded transfer serialization`

---

## Phase 4 - Balances, snapshots, and verification

**Goal**: Fast, provably correct balance reads: current and as-of queries, the snapshot
accelerator, and the verify command that re-proves everything from postings.

### Definition of done

- `Ledger::balance($account)`: latest snapshot + tail sum past `last_posting_id`, or full sum
  when no snapshot; `Ledger::balanceAsOf($account, $at)`: full sum over `posted_at <= $at`,
  never snapshot-assisted. Both pure integer SQL aggregates.
- `SnapshotBuilder::build($account)`: account row lock, boundary =
  `MAX(id) where created_at <= now() - safety_window`, incremental compute from the previous
  snapshot, append-only insert, idempotent re-run; respects `snapshot.min_tail`.
- `ledger:snapshot {--account=}` builds for one or all accounts and reports rows written;
  `ledger:verify` re-proves entry zero-sums, posting/entry `posted_at` agreement, and snapshot
  balance + count against full recomputation; exit 0/1; both commands print the `code: message`
  error format on failure.
- Structured logs: `ledger.snapshot_built`, `ledger.verify_passed`, `ledger.verify_failed`.
- Pint clean; tests: balance with and without snapshots equal, as-of ordering by `posted_at`
  (backdated entry counted correctly), snapshot + tail = full recompute invariant across
  interleaved writes, safety window excludes young postings, re-run is a no-op, verify passes
  clean and fails with precise ids on a planted-bad snapshot, min_tail skip.

### Verification

- Seed 10k postings across accounts; `EXPLAIN` confirms `(account_id, id)` serves the tail sum
  and `(account_id, posted_at)` serves as-of on Postgres.
- Unhappy paths: snapshot build on an account with no postings is a clean no-op; verify on an
  empty ledger exits 0; concurrent `ledger:snapshot` runs for the same account serialize on the
  account lock and produce one snapshot.

### Commits

1. `feat: add current and as of balance queries`
2. `feat: add snapshot builder with safety window`
3. `feat: add ledger snapshot command`
4. `feat: add ledger verify command`
5. `test: cover balances snapshot consistency and verify`

---

## Phase 5 - Demo, hardening, and release readiness

**Goal**: A new user can see the whole surface in one command, the package is documented as
built, and the release checklist is executable.

### Definition of done

- `ledger:demo`: scripted scenario on the configured connection (guarded by a confirmation
  prompt naming the database): creates `demo.*` accounts, posts entries, a transfer, an
  idempotent retry (shows the replay), a reversal, prints a balance table, runs verify; exits 0.
- README finalized: install, migrate, quickstart code, command reference, invariants summary,
  MySQL-vs-Postgres enforcement note; planning-stage status line removed.
- `docs/testing.md` commands verified exactly as written; CI matrix (pgsql + mysql) green;
  `composer.lock` pinned; CHANGELOG started at `0.1.0`.
- Full-suite pass plus a manual `ledger:demo` run on both engines recorded in
  `docs/memory.md`.

### Verification

- Fresh clone → `composer install` → configure test DBs → `vendor/bin/pest` green on both
  engines with no extra steps beyond `docs/testing.md`.
- `ledger:demo` output hand-checks: the retry prints the same entry id, the reversal zeroes the
  pair, verify reports clean.
- Unhappy paths: demo aborted at the prompt changes nothing; demo re-run creates a second set
  of demo entries without colliding (fresh idempotency keys per run).

### Commits

1. `feat: add ledger demo command`
2. `docs: finalize readme with quickstart and command reference`
3. `chore: add changelog and release metadata`
4. `test: cover demo command end to end`
5. `docs: record release readiness in memory`

---

## Backlog

- Account archiving (block new postings, keep history) - deferred: v1 keeps accounts always
  postable to avoid a second lifecycle state machine.
- `normalBalance()` presentation helper (negate for credit-normal types) - deferred: callers
  can negate; adding it later is non-breaking.
- Table-name prefix config - deferred: static identifiers keep trigger DDL simple and safe.
- Event hooks (entry posted callbacks) - deferred until three concrete consumer use cases
  exist, per rules.
- Statement/report generation and account hierarchies - out of scope for the library layer.
