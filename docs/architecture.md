# Architecture - ledgerkit

## Posting flow

```
Caller (application code)
        │  Ledger::entry()->debit(...)->credit(...)->post()
        │  or Ledger::transfer($from, $to, $amount, ...)
        ▼
Draft validation (in PHP, before any SQL)
        ├─ at least 2 lines                      → invalid_entry
        ├─ every amount a nonzero integer        → invalid_amount
        ├─ every account exists                  → unknown_account
        ├─ line currency = account currency      → currency_mismatch
        ├─ per-currency sum of amounts = 0       → unbalanced_entry
        └─ idempotency key present? compute fingerprint (sha256 of sorted lines)
                │
                ▼
DB transaction (short-lived: two inserts, nothing else)
        ├─ INSERT ledger_entries (ulid id, key, fingerprint, metadata, posted_at)
        ├─ INSERT ledger_postings (one multi-row statement, all lines)
        └─ COMMIT
             ├─ PostgreSQL: deferred constraint trigger re-checks the zero-sum
             │  and 2+ line invariants at commit; an unbalanced bypass aborts here
             └─ unique violation on idempotency_key → rollback, re-fetch by key:
                   fingerprint matches   → return existing entry (replayed = true)
                   fingerprint differs   → IdempotencyConflictException
                │
                ▼
Result: immutable Entry with its Postings; corrections only via Ledger::reverse()
```

Balance reads never lock and never write:

```
Ledger::balance($account)
        ├─ latest snapshot for account?  yes → snapshot.balance
        │                                      + SUM(amount) WHERE id > snapshot.last_posting_id
        └─ no snapshot                       → SUM(amount) over all the account's postings
Ledger::balanceAsOf($account, $at)           → SUM(amount) WHERE posted_at <= $at (never uses
                                               snapshots; boundary is id-ordered, not time-ordered)
```

## Tech stack with rationale

- **PHP 8.3, composer package** - `thealirazadev/ledgerkit`, namespace `LedgerKit\`. Native enums
  for account types, readonly value objects for drafts. `ext-bcmath` is required for the only
  derived arithmetic in the package (decimal-string input conversion); core sums are integer SQL.
- **illuminate/database, illuminate/support, illuminate/console ^11** - The package consumes
  Laravel 11 components, not the full framework: Eloquent models, the schema builder and
  migrations, and the console kernel for the three artisan commands. A service provider wires
  everything; the host app publishes nothing except (optionally) the config file.
- **PostgreSQL 15+ (primary), MySQL 8.x (supported)** - Postgres is primary because it is the
  only engine of the two that can enforce the balance invariant in the database itself
  (deferrable constraint triggers checked at commit). MySQL gets everything except that final
  commit-time re-check; the difference is documented in "Database-enforced invariants" below.
  SQLite is rejected at migration time with `unsupported_driver`: it lacks the triggers and row
  locks the correctness story depends on, and pretending to support it would ship a silently
  weaker ledger.
- **Pest on PHPUnit + orchestra/testbench ^9** - Testbench boots a minimal Laravel app around
  the package; tests run against real Postgres and MySQL (no SQLite stand-in), because the
  invariants under test live in the database. See `docs/testing.md`.
- **Laravel Pint** - PSR-12, zero config.

No other runtime dependencies. No money library: amounts are plain integers plus a currency code,
and the package's value objects are ~50 lines, not a dependency.

## System components

- **`Ledger` facade / `LedgerService`** - The single public entry point: account creation and
  lookup, the entry draft builder, `post()`, `transfer()`, `reverse()`, `balance()`,
  `balanceAsOf()`, and the cooperative lock helper. All writes go through it; models are exposed
  read-only.
- **Eloquent models** - `Account`, `Entry`, `Posting`, `BalanceSnapshot`. `Entry` and `Posting`
  throw `ImmutableRecordException` from overridden `update`/`delete` as a first line of defense;
  the database triggers are the real enforcement.
- **`EntryDraft`** - Immutable builder collecting lines and metadata; performs all PHP-side
  validation in `post()` before any SQL runs.
- **`IdempotencyFingerprint`** - Canonicalizes a draft's lines (sorted `account_id:amount:
  currency` tuples, newline-joined) and hashes with sha256. Lives in one place so the write path
  and the conflict path can never disagree on the canonical form.
- **`SnapshotBuilder`** - Computes and appends balance snapshots per account under the account
  row lock; used by `ledger:snapshot` and callable directly.
- **Artisan commands** - `ledger:demo`, `ledger:verify`, `ledger:snapshot`; see `docs/design.md`
  for their UX and `docs/api-contracts.md` for exact I/O.
- **Migrations** - Publishable, engine-aware: shared schema through the schema builder, plus
  per-driver raw DDL for CHECK constraints and triggers. Unsupported drivers abort the migration.

## Data model

Tables named here are the contract; the coding agent must not rename them. All tables use the
`ledger_` prefix so the package coexists with any host schema. Amounts are **signed bigints in
minor units, debit-positive**: a debit is stored positive, a credit negative, so "balanced" is
exactly `SUM(amount) = 0` per currency and no debit/credit column can disagree with its amount.

### ledger_accounts
| Field | Type | Notes |
|---|---|---|
| id | bigint PK (identity) | internal handle |
| code | string(64), unique | caller-chosen stable identifier, e.g. `cash.main` |
| name | string(255) | display name |
| type | string(16) | `asset` \| `liability` \| `income` \| `expense` \| `equity`; CHECK-constrained |
| currency | char(3) | ISO 4217 uppercase; CHECK `^[A-Z]{3}$` |
| timestamps | | accounts are editable (name only); code, type, currency are write-once in the API |

Indexes: unique `code`; unique `(id, currency)` - exists solely to be the target of the
composite foreign key from `ledger_postings`, which is what makes "posting currency equals
account currency" a database guarantee on both engines with no trigger.

### ledger_entries
| Field | Type | Notes |
|---|---|---|
| id | char(26) ULID PK | safe to expose in the host application |
| idempotency_key | string(191), nullable, unique | the idempotency guarantee |
| idempotency_fingerprint | char(64), nullable | sha256 of canonical lines; set iff key is set |
| description | string(255), nullable | audit metadata |
| external_ref | string(191), nullable, indexed | joins back to the caller's domain object |
| actor | string(191), nullable | who/what posted, e.g. `user:42`, `system:billing` |
| reverses_entry_id | char(26), nullable, FK → ledger_entries.id, **unique** | set on reversing entries; unique makes double reversal impossible even under race |
| posted_at | timestamp | business effective time; defaults to now; drives as-of queries |
| created_at | timestamp | no updated_at: entries are immutable |

### ledger_postings
| Field | Type | Notes |
|---|---|---|
| id | bigint PK (identity) | monotone sequence; the snapshot boundary key |
| entry_id | char(26) FK → ledger_entries.id | ON DELETE RESTRICT; indexed |
| account_id | bigint | with currency below, composite FK → ledger_accounts (id, currency) |
| currency | char(3) | must equal the account's currency (composite FK enforces) |
| amount | bigint | signed minor units, debit-positive; CHECK `amount <> 0` |
| posted_at | timestamp | denormalized copy of the entry's posted_at for as-of queries |
| created_at | timestamp | immutable rows; no updated_at |

Indexes: `(account_id, id)` - snapshot tail sums; `(account_id, posted_at)` - as-of queries;
`entry_id` - entry detail and the commit-time balance trigger.

### ledger_balance_snapshots
| Field | Type | Notes |
|---|---|---|
| id | bigint PK (identity) | |
| account_id | bigint FK → ledger_accounts.id | |
| last_posting_id | bigint | boundary, inclusive: snapshot covers postings with id <= this |
| balance | bigint | SUM(amount) of covered postings |
| posting_count | bigint | count of covered postings; lets `ledger:verify` cross-check cheaply |
| created_at | timestamp | append-only; reads use the row with the highest last_posting_id |

Indexes: unique `(account_id, last_posting_id)` - re-running the builder is idempotent; also
serves the latest-snapshot lookup.

## Database-enforced invariants

Application validation is the first line; these hold even if application code is bypassed.

Both engines:
- `ledger_postings.amount <> 0` (CHECK).
- `ledger_accounts.currency` format and `type` membership (CHECK).
- Posting currency equals account currency (composite FK, no trigger needed).
- Immutability: `BEFORE UPDATE` and `BEFORE DELETE` row triggers on `ledger_postings` and
  `ledger_entries` raise an error (Postgres `RAISE EXCEPTION`, MySQL `SIGNAL SQLSTATE '45000'`).
- One reversal per entry (unique index on `reverses_entry_id`).
- At most one entry per idempotency key (unique index).

PostgreSQL only:
- **Balanced-at-commit**: a `CONSTRAINT TRIGGER AFTER INSERT ON ledger_postings DEFERRABLE
  INITIALLY DEFERRED` runs at commit for each inserted row and verifies its entry has >= 2
  postings and a zero per-currency sum. Deferral matters: postings necessarily insert one
  statement at a time, so the invariant is only checkable once the whole entry is in. Any
  writer, including raw SQL, gets aborted at commit if the entry does not balance. The trigger
  re-checks per inserted row (so an N-line entry checks N times); N is small and correctness
  beats the micro-optimization of dedup-at-commit.

MySQL limitation (documented trade-off, not an oversight): MySQL has no deferrable constraint
triggers and no statement-level triggers, so balanced-at-commit cannot be expressed in the
database. On MySQL the zero-sum invariant is enforced by the library's validation inside the
posting transaction, immutability triggers still hold, and `ledger:verify` re-proves every
entry's sums from the stored postings. This asymmetry is why PostgreSQL is the primary target.

## Key flows step by step

**Post an entry** (`EntryDraft::post()`):
1. Validate in PHP: line count, integer nonzero amounts, accounts resolved in one query, line
   currency equals account currency, per-currency sums are zero. Amount inputs are `int` or
   decimal strings; decimal strings convert via bcmath against the currency's minor-unit
   exponent and reject anything with sub-minor precision. Floats are rejected outright.
2. If an idempotency key is present, compute the fingerprint from the canonical line form.
3. Open a transaction on the configured connection: insert the entry row, insert all postings in
   one multi-row statement (each carrying the entry's `posted_at`), commit. Nothing else happens
   inside the transaction; keeping it two statements long is what makes the snapshot safety
   window (below) a mild assumption instead of a wishful one.
4. Unique violation on `idempotency_key` (SQLSTATE 23505 / MySQL 1062): roll back, fetch the
   existing entry by key. Fingerprint match → return it with `replayed = true`. Mismatch →
   `IdempotencyConflictException`. This is the concurrent-retry race handled by the constraint,
   not by a check-then-insert.

**Transfer** (`Ledger::transfer($from, $to, $amountMinor, ...)`):
1. Resolve both accounts; currencies must match or `currency_mismatch`.
2. Build a two-line draft: the amount moves from `$from` to `$to`, so `$from` gets the negative
   (credit) posting and `$to` the positive (debit) posting in raw signed terms. Callers reason
   in "move X from A to B"; the sign convention stays internal.
3. Post exactly like any other entry (same idempotency, same validation, same invariants).

**Guarded transfer** (`Ledger::transferGuarded($from, $to, $amountMinor, $guard, ...)`):
1. Inside one transaction: `SELECT ... FOR UPDATE` on the `$from` account row, compute the full
   balance from postings (sees all committed postings plus this transaction's own), call the
   guard closure with it, and post the transfer only if the guard passes.
2. The lock is **cooperative**: it serializes every writer that uses the guarded path against
   the same account, which is exactly what an overdraft check needs. It does not serialize
   lock-free `post()` calls; if every debit of an account must respect a floor, every debit of
   that account must go through the guarded path. This is documented API contract, not a bug.

**Reverse** (`Ledger::reverse($entry, ...)`):
1. Build a draft with every posting of the original negated, `reverses_entry_id` set, fresh
   metadata (description defaults to `reversal of {id}`), `posted_at` defaulting to now.
2. Post normally. A concurrent double-reverse loses the unique-index race on
   `reverses_entry_id` and surfaces `AlreadyReversedException` after re-checking the cause.
3. Reversal never touches the original entry; the books show both, summing to zero.

**Balance snapshot build** (`SnapshotBuilder::build($account)`):
1. In a transaction, `SELECT ... FOR UPDATE` the account row: serializes concurrent builds for
   the same account and the guarded-transfer path, cheaply.
2. Choose the boundary: `MAX(id)` of the account's postings with
   `created_at <= now() - safety_window` (config `snapshot.safety_window_seconds`, default 300).
   Why not plain `MAX(id)`: sequence ids commit out of order. Transaction A can obtain id 100,
   stall, and commit after transaction B commits id 101. A snapshot taken at boundary 101 before
   A commits would silently omit posting 100 forever. The safety window makes the boundary older
   than any plausibly in-flight write; it is sound whenever no posting transaction lives longer
   than the window, which the two-statement write transaction makes easy and
   `docs/launch-checklist.md` makes an operator check.
3. If the boundary does not exceed the latest snapshot's boundary, do nothing (idempotent).
4. Compute incrementally: previous snapshot balance + SUM/COUNT of postings in
   `(previous_boundary, boundary]`; insert the new snapshot row. Append-only: history is kept
   and the unique `(account_id, last_posting_id)` makes re-runs no-ops.

**Verify** (`ledger:verify`):
1. Every entry: >= 2 postings, per-currency sums zero, posting `posted_at` matches the entry.
2. Every account's latest snapshot: recompute SUM/COUNT over `id <= last_posting_id` and compare
   to the stored balance and posting_count (full recomputation, deliberately not incremental:
   verify must not share code or assumptions with the thing it checks).
3. Report violations with ids; exit 0 clean / 1 dirty. Read-only; safe on production.

## Correctness invariants

- **I1 Zero-sum**: for every entry and currency, `SUM(postings.amount) = 0`. Enforced in PHP on
  both engines and at commit by Postgres. `ledger:verify` re-proves it from storage.
- **I2 Immutability**: no UPDATE or DELETE ever succeeds against entries or postings; the only
  way to change the books' meaning is to append. Enforced by triggers on both engines.
- **I3 Idempotency**: at most one entry per idempotency key (unique index); a replay returns the
  original iff the economic content (canonical lines) matches. Safe under concurrent retries
  because the constraint, not a read, decides the winner.
- **I4 Currency integrity**: a posting's currency always equals its account's currency
  (composite FK), so no balance ever mixes currencies.
- **I5 Snapshot consistency**: for any snapshot, `balance = SUM(amount, id <= boundary)`, and
  therefore `latest snapshot + tail sum = full recomputation`. Holds given the safety-window
  assumption (no write transaction outlives the window); checked, not assumed, by
  `ledger:verify`.
- **I6 Single reversal**: at most one reversing entry per entry (unique index), race-proof.
- **I7 Balance derivation**: account balances are never stored as authoritative state; the
  postings are the truth and everything else is a cache or a computation. Concurrent lock-free
  posts to the same account therefore cannot corrupt a balance - there is no balance row to
  corrupt, only appends that commute.

## Failure modes and handling

| Failure | Handling |
|---|---|
| Unbalanced draft | `UnbalancedEntryException` before any SQL; nothing persisted. |
| Unbalanced raw-SQL bypass (Postgres) | Commit aborts via the deferred constraint trigger; transaction rolls back whole. |
| Unbalanced raw-SQL bypass (MySQL) | Not blocked at commit (engine limitation); detected by `ledger:verify`; documented. |
| Idempotency-key race (two concurrent identical posts) | One insert wins; the loser catches the unique violation, re-fetches, fingerprint-matches, returns the winner's entry as replayed. |
| Same key, different lines | `IdempotencyConflictException` with both fingerprints logged (never the lines themselves at error level). |
| Crash after entry insert, before postings insert | Same transaction, so both roll back; no partial entry can exist. |
| Concurrent double reversal | Loser hits the `reverses_entry_id` unique index; `AlreadyReversedException`. |
| Two concurrent guarded transfers from one account | Second blocks on the row lock, then sees the first's postings in its balance read; guard decides with fresh data. |
| Snapshot built while writes are in flight | Safety window keeps the boundary behind any in-flight write; out-of-order sequence commits cannot be silently skipped. |
| Snapshot corruption (bug, manual meddling) | `ledger:verify` reports account and snapshot id; repair is deleting snapshots (they are cache) and rebuilding; postings are untouched truth. |
| Unsupported driver (SQLite etc.) | Migrations throw `unsupported_driver` immediately with a clear message; nothing half-applies. |
| Deadlock / serialization failure on post | Surfaced to the caller as the driver exception; the transaction is short and retry-safe (idempotency keys make retries free), so the library does not auto-retry. |
| Connection loss mid-transaction | Driver exception propagates; transaction rolls back server-side; retry with the same idempotency key is the recovery path. |

## Proposed folder / file tree

```
composer.json                              # thealirazadev/ledgerkit; php 8.3, ext-bcmath,
                                           # illuminate/{database,support,console} ^11 (pinned)
config/ledgerkit.php                       # connection, snapshot.safety_window_seconds,
                                           # snapshot.min_tail
database/migrations/
├── 0001_create_ledger_accounts_table.php  # table + CHECKs + unique (id, currency)
├── 0002_create_ledger_entries_table.php   # table + unique key + unique reverses_entry_id
├── 0003_create_ledger_postings_table.php  # table + composite FK + CHECK amount <> 0
├── 0004_create_ledger_snapshots_table.php
└── 0005_create_ledger_triggers.php        # per-driver DDL: immutability both engines,
                                           # deferred balance constraint trigger on pgsql;
                                           # throws unsupported_driver otherwise
src/
├── LedgerServiceProvider.php              # config, migrations, commands, singleton binding
├── Facades/Ledger.php
├── LedgerService.php                      # accounts, post, transfer(Guarded), reverse, balances
├── AccountType.php                        # backed enum: asset|liability|income|expense|equity
├── EntryDraft.php                         # fluent builder + all PHP-side validation
├── PostedEntry.php                        # result object: entry, postings, replayed flag
├── IdempotencyFingerprint.php             # canonical line form + sha256
├── SnapshotBuilder.php                    # locked, windowed, idempotent snapshot append
├── Models/
│   ├── Account.php
│   ├── Entry.php                          # guards update/delete; hasMany postings
│   ├── Posting.php                        # guards update/delete
│   └── BalanceSnapshot.php
├── Exceptions/
│   ├── LedgerException.php                # abstract base; errorCode(): string
│   ├── UnbalancedEntryException.php
│   ├── InvalidAmountException.php
│   ├── UnknownAccountException.php
│   ├── InvalidAccountException.php
│   ├── CurrencyMismatchException.php
│   ├── IdempotencyConflictException.php
│   ├── AlreadyReversedException.php
│   ├── ImmutableRecordException.php
│   └── UnsupportedDriverException.php
└── Console/
    ├── DemoCommand.php                    # ledger:demo
    ├── VerifyCommand.php                  # ledger:verify
    └── SnapshotCommand.php                # ledger:snapshot

tests/
├── TestCase.php                           # testbench boot; connection from env
├── Feature/
│   ├── AccountTest.php
│   ├── PostEntryTest.php                  # balanced, unbalanced, multi-currency, metadata
│   ├── ImmutabilityTest.php               # model guards + raw-SQL trigger rejection
│   ├── DbEnforcementTest.php              # pgsql-only: unbalanced bypass aborts at commit
│   ├── IdempotencyTest.php                # sequential + concurrent (second connection) races
│   ├── ReversalTest.php
│   ├── TransferTest.php                   # incl. guarded-transfer serialization race
│   ├── BalanceTest.php                    # current, as-of, snapshot + tail = full recompute
│   ├── SnapshotBuilderTest.php            # window, idempotence, out-of-order boundary case
│   └── CommandsTest.php                   # demo, verify (clean + planted corruption), snapshot
└── Unit/
    ├── EntryDraftTest.php
    ├── IdempotencyFingerprintTest.php
    └── AmountConversionTest.php           # bcmath decimal-string paths, float rejection
```

## Where state lives

- **Database (single source of truth)** - accounts, entries, postings, snapshots. Postings are
  the only authoritative money state; snapshots are a rebuildable cache and say so in their doc
  block. The package holds no state outside the database: no files, no cache driver, no queue.
- **Config** - `ledgerkit.connection` (null = the host's default connection),
  `snapshot.safety_window_seconds` (default 300), `snapshot.min_tail` (default 1000: skip
  building when the tail is shorter). Code reads config, never `env()`.
- **Host application** - owns the connection credentials, migration running, and scheduling of
  `ledger:snapshot`/`ledger:verify` if it wants them recurring. The package prescribes nothing.
