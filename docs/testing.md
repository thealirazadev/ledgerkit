# Testing - ledgerkit

## Strategy

- **The invariants live in the database, so the tests do too.** The suite runs against real
  PostgreSQL and real MySQL via orchestra/testbench; there is no SQLite stand-in, because the
  things under test (deferred constraint triggers, immutability triggers, composite FKs, row
  locks, unique-violation races) do not exist or behave differently there. Connection DSNs come
  from env (`.env.example` carries dummies for both engines).
- **Pest on PHPUnit.** Feature tests exercise the public API against the database; unit tests
  cover the pure pieces (`EntryDraft` validation, `IdempotencyFingerprint`, amount conversion)
  without a connection where possible.
- **Races are tested with a second connection, not with sleeps.** Concurrency tests open a
  second database connection in the same process and drive both sides deterministically:
  the idempotency race commits the competing row on connection B before A's insert resolves;
  the guarded-transfer test proves B blocks on A's row lock and then observes A's postings.
  No timing assumptions, no flaky sleeps.
- **Bypass tests use raw SQL on purpose.** DB-enforcement tests (unbalanced commit abort on
  Postgres, trigger rejection of UPDATE/DELETE, composite-FK currency mismatch, CHECK
  violations) issue raw statements deliberately, because their subject is what happens when the
  library is bypassed. These are the only raw money-row writes in the repo, per
  `docs/rules.md`.
- **Engine-conditional tests are explicit.** Postgres-only behavior (deferred balance trigger)
  lives in tests skipped with a named reason on MySQL, so the MySQL run documents the
  difference instead of hiding it.

### What to cover

Unit:
- Draft validation: line count, zero/float/overflow amounts, decimal-string conversion per
  currency exponent (bcmath), per-currency sum checks, metadata length caps.
- Fingerprint: canonical ordering (same lines, any order → same hash), sensitivity to any
  economic change, insensitivity to description/actor.

Feature:
- Accounts: creation, validation, duplicate code, lookup, raw CHECK/FK backstops.
- Posting: balanced single/multi-currency entries persist atomically; every rejection path
  persists nothing; `posted_at` denormalization.
- Immutability: model guards and both engines' triggers; reversal semantics; double-reversal
  sequential and concurrent.
- Idempotency: sequential replay, concurrent race, conflict on changed lines, key length
  bounds.
- Transfers: happy path, currency mismatch, guarded serialization, guard exception rollback.
- Balances and snapshots: current/as-of correctness, snapshot + tail = full recomputation
  across interleaved writes, safety window, builder idempotence and locking, min_tail.
- Commands: demo end to end (`--yes`), verify clean and planted-corruption exits, snapshot
  command output and flags.

## Exact commands

```bash
# Full suite (uses DB_* values from .env / environment)
vendor/bin/pest

# Against a specific engine
LEDGERKIT_TEST_DB=pgsql vendor/bin/pest
LEDGERKIT_TEST_DB=mysql vendor/bin/pest

# A single file
vendor/bin/pest tests/Feature/IdempotencyTest.php

# Filter by test name
vendor/bin/pest --filter="concurrent same key"

# Formatting check (must pass; PSR-12 via Pint)
vendor/bin/pint --test
```

First-time setup:

```bash
composer install
cp .env.example .env    # point DB_* at local postgres and mysql test databases
```

Both engines must exist locally (or via docker) with empty test databases; testbench migrates
fresh per run.

## CI plan

GitHub Actions on push and pull request to `main`: a two-job matrix, one with a `postgres:15`
service and one with `mysql:8` (both with health checks), each running `vendor/bin/pint --test`
then `vendor/bin/pest` on PHP 8.3 with `ext-bcmath`. A phase is not done until both jobs are
green.

## Definition of "done" for a feature

1. `vendor/bin/pint --test` - no style violations.
2. `vendor/bin/pest` green on **both** engines locally (or both CI jobs green).
3. The feature's verification items in `docs/phases.md` pass.

One commit per feature, in the order listed in `docs/phases.md`.
