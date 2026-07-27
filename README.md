# ledgerkit

A double-entry money ledger library for Laravel, planned as a composer package. Applications
that track money internally usually start with a `balance` column and a log table, and pay for
it later: drifting balances, double-posts on retried calls, races between concurrent writes,
and no way to prove what a balance was on a past date. ledgerkit is planned to replace that with
a real ledger: typed accounts, journal entries whose postings must balance to zero per currency,
immutable postings with corrections as reversing entries, idempotency keys on entry creation,
and exact integer balance queries - with the invariants pushed into the database itself wherever
the engine can enforce them, so even code that bypasses the library cannot corrupt the books.

## The problem it solves

Money state kept as mutable balance columns cannot answer "why is this number what it is", and
it breaks quietly: a retried webhook posts twice, two concurrent transfers interleave, a bug
UPDATEs history. A double-entry ledger makes every change an append-only, balanced, attributable
record, and makes every balance a provable sum. ledgerkit is that ledger as a library, so a
Laravel application gets bank-grade bookkeeping semantics without building them from scratch.

## Planned features

- Accounts with a unique code, one of five types (asset, liability, income, expense, equity),
  and a fixed ISO 4217 currency.
- Journal entries of two or more postings that must sum to zero per currency, stored as signed
  integer minor units; no floats anywhere, bcmath for any derived arithmetic.
- Immutable entries and postings: UPDATE and DELETE rejected by database triggers on both
  supported engines; corrections are reversing entries, at most one reversal per entry.
- Database-enforced balancing on PostgreSQL via a deferrable constraint trigger checked at
  commit; a documented, narrower guarantee on MySQL.
- Idempotency keys on entry creation, correct under the concurrent-retry race: a retry returns
  the original entry, a changed payload under the same key raises a conflict.
- Current and as-of-date balance queries computed from postings, with an optional append-only
  snapshot table for fast current-balance reads and a verify command that proves snapshot plus
  tail equals full recomputation.
- A transfer helper for the common two-account case, plus a guarded variant that locks the
  source account row for overdraft-style checks.
- Audit metadata on every entry: description, external ref, actor, and a business `posted_at`
  distinct from insertion time.
- Artisan commands: `ledger:demo` (scripted end-to-end scenario), `ledger:verify` (read-only
  invariant proof), `ledger:snapshot` (balance snapshot builder).

## Stack

- PHP 8.3, composer package (`illuminate/database`, `illuminate/support`,
  `illuminate/console` ^11), `ext-bcmath`
- PostgreSQL 15+ (primary) / MySQL 8.x (supported); SQLite intentionally unsupported
- Pest on PHPUnit with orchestra/testbench, run against both real engines
- Laravel Pint (PSR-12)

## Docs

| File | Contents |
|---|---|
| [docs/PRD.md](docs/PRD.md) | Problem, target user, user stories, features, success criteria |
| [docs/architecture.md](docs/architecture.md) | Stack rationale, data model, flows, failure modes, invariants |
| [docs/rules.md](docs/rules.md) | Project-specific engineering rules |
| [docs/phases.md](docs/phases.md) | Implementation phases with commit lists and verification |
| [docs/design.md](docs/design.md) | API ergonomics and CLI output design |
| [docs/testing.md](docs/testing.md) | Test strategy, commands, CI plan |
| [docs/api-contracts.md](docs/api-contracts.md) | Public PHP API, commands, error codes, config |
| [docs/launch-checklist.md](docs/launch-checklist.md) | Pre-release gates |
| [docs/memory.md](docs/memory.md) | Working log and decisions |

## Status

Planning stage: these documents are the complete specification and no implementation code
exists yet. Implementation will follow `docs/phases.md` one phase at a time, starting with
Phase 1 after owner review of these docs.
