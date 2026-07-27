# Product Requirements - ledgerkit

## What we're building

A double-entry money ledger library for Laravel, shipped as a composer package. Applications that
move money (wallets, marketplaces, billing systems, internal credit systems) almost always start
with a `balance` column and a log table, and almost always regret it: balances drift, concurrent
writes race, retried API calls double-post, and nobody can prove what the balance was last
Tuesday. ledgerkit replaces that with a real ledger: typed accounts with a fixed currency;
journal entries of two or more postings that must sum to zero per currency; immutable postings
where every correction is a reversing entry; idempotency keys so a retried call cannot post
twice; and balance queries (current and as-of-date) derived from the postings themselves,
optionally accelerated by a snapshot table. The invariants are enforced in the database wherever
the engine allows, so even code that bypasses the library cannot corrupt the books.

## Target user

A Laravel developer building a product that tracks money internally and needs the books to be
provably correct: no floats, no lost cents, no double-posts on retry, no balance drift under
concurrency, and an audit trail that survives bugs in the calling application. The deliverable is
a library plus migrations plus an artisan demo command; there is no UI and no HTTP surface.

## Core user stories

- As a developer, I define accounts with a type and currency and get back stable account handles
  I can post against from anywhere in my application.
- As a developer, I record a journal entry with multiple postings and the library refuses it
  unless it balances to zero per currency, so an unbalanced write is impossible.
- As a developer, I pass an idempotency key when posting, so my payment webhook handler can be
  retried safely and the second call returns the original entry instead of double-posting.
- As a developer, I call one transfer helper for the common two-account move instead of
  hand-building a two-line entry every time.
- As a developer, I correct a mistake by reversing an entry, and the original stays in the books
  untouched, so the audit trail is complete.
- As a developer, I query an account's current balance or its balance as of a past date and get
  an exact integer answer computed from the postings.
- As an operator, I run a verify command that recomputes everything from postings and proves the
  snapshots and entry sums are consistent, so I can trust the optimization.

## Core features (prioritized)

1. **Accounts** - Create and fetch accounts with a unique code, a display name, one of five
   types (`asset`, `liability`, `income`, `expense`, `equity`), and a fixed ISO 4217 currency.
   An account's currency never changes after creation.

2. **Balanced journal entries** - An entry is two or more postings, each an integer amount in
   minor units against one account. The library rejects any entry whose postings do not sum to
   zero per currency, and on PostgreSQL the database itself re-checks the invariant at commit,
   so a bypassing writer cannot break the books.

3. **Immutable postings** - Postings and entries are never updated or deleted; database triggers
   reject UPDATE and DELETE on both engines. Corrections are reversing entries created by a
   `reverse()` helper, and an entry can be reversed at most once (enforced by a unique index).

4. **Idempotent posting** - An optional idempotency key on entry creation is backed by a unique
   index. A retried call with the same key and the same economic content returns the original
   entry; the same key with different content raises a conflict error. Correct under the
   concurrent-retry race, not just the sequential one.

5. **Balance queries** - Current balance and balance as-of-date for any account, computed from
   postings with integer arithmetic. An optional append-only snapshot table accelerates current
   balance reads; snapshot plus postings-after-snapshot always equals full recomputation, and a
   `ledger:verify` command proves it.

6. **Transfer helper** - `transfer(from, to, amount)` builds the standard two-posting entry for
   same-currency account pairs, with an optional locked balance guard for overdraft-style checks.

7. **Audit metadata** - Every entry carries optional `description`, `external_ref` (indexed, for
   joining back to the caller's domain objects), and `actor` fields, plus a business `posted_at`
   timestamp distinct from the row creation time.

8. **Demo command** - `php artisan ledger:demo` runs a scripted end-to-end scenario (accounts,
   entries, a transfer, an idempotent retry, a reversal, balance reads) so a new user sees the
   whole surface in one run against a scratch database.

## Non-goals

- Currency conversion or exchange rates: an entry may span currencies, but it must balance to
  zero within each currency independently; the library never converts.
- Invoicing, tax logic, payment provider integrations, or any business-domain modeling above
  the ledger itself; likewise no UI, HTTP API, or dashboard of any kind.
- Floating-point amounts in any code path; integer minor units end to end, bcmath for any
  derived arithmetic.
- SQLite support: the invariants depend on triggers and row locks that SQLite cannot express;
  supported drivers are PostgreSQL (primary) and MySQL.
- Multi-tenancy helpers, account hierarchies, or reporting/statement generation in v1.

## Success criteria per core feature

- **Accounts** - Creating an account with a duplicate code fails cleanly; the five types and the
  currency format are validated; an account's currency is fixed by a database constraint that
  also blocks postings in any other currency.
- **Balanced entries** - A balanced multi-currency entry posts; an unbalanced one raises
  `unbalanced_entry` and persists nothing; on PostgreSQL a raw SQL insert of unbalanced postings
  fails at commit; a one-posting entry is rejected everywhere.
- **Immutability** - UPDATE and DELETE against `ledger_postings` and `ledger_entries` fail at
  the database level on both engines; `reverse()` produces a negated entry linked to the
  original; a second `reverse()` of the same entry fails, including under a concurrent race.
- **Idempotency** - Two sequential posts with the same key yield one entry, the second flagged
  as replayed; two concurrent posts with the same key yield exactly one entry with both callers
  receiving it; the same key with different lines raises `idempotency_conflict`.
- **Balances** - Current and as-of balances match hand-computed sums; as-of respects `posted_at`,
  not insertion order; with snapshots enabled, snapshot + tail equals full recomputation for
  every account, and `ledger:verify` exits 0 on a healthy ledger and 1 with a precise report
  when a snapshot is planted wrong.
- **Transfers** - A transfer moves the amount between two same-currency accounts in one entry; a
  cross-currency transfer raises `currency_mismatch`; two concurrent guarded transfers from one
  account serialize on the account lock and the guard sees the first transfer's effect.
- **Demo command** - On a fresh scratch database, `ledger:demo` runs the full scenario, prints
  balances that hand-check, and exits 0.
