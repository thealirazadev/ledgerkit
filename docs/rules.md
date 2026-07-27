# Engineering Rules - ledgerkit

These rules are binding for every change in this repository. They extend the workspace-level
rules in the parent `CLAUDE.md`; where both speak, the stricter one wins.

## Conventions

- **Package, not app**: There is no HTTP layer, no views, no routes. The public surface is the
  `Ledger` facade, the models (read-only), the exceptions, the migrations, and three artisan
  commands. Anything else is `@internal` and may change without notice.
- **All writes through `LedgerService`**: No model `create()`/`save()` calls for entries or
  postings anywhere except the one posting path inside `LedgerService`. Reversal, transfer, and
  the demo command all call `post()`; there is exactly one code path that inserts money rows.
- **Money is integers**: Amounts are `int` minor units everywhere in the package. The only
  non-integer input accepted is a decimal string, converted once at the draft boundary with
  bcmath against the currency exponent. A `float` amount anywhere (parameter, cast, config,
  test fixture) is a defect. No arithmetic on amounts outside SQL `SUM` and bcmath.
- **Engine parity discipline**: Every migration and query must run on both pgsql and mysql.
  Driver-specific DDL (triggers, CHECK syntax) lives only in migrations behind an explicit
  driver switch that throws `UnsupportedDriverException` for anything else. No driver-specific
  SQL in `src/` outside those migrations.
- **Naming (PSR-12 + Laravel)**: Models singular `PascalCase` (`Account`, `Entry`, `Posting`,
  `BalanceSnapshot`); tables `ledger_` + plural snake_case, exactly as fixed in
  `docs/architecture.md` - do not rename; exceptions end in `Exception`; commands are
  `ledger:verb`. Pint enforces style.
- **Commit format**: Conventional Commits, short imperative subject, e.g.
  `feat: add entry draft validation`, `fix: cap external ref at column width`.
- **ONE COMMIT PER FEATURE**: Each feature or task is exactly one commit; never batch features,
  never fragment one small feature. The commit lists in `docs/phases.md` are the intended order.
- **Pin exact dependency versions**: Exact versions in `composer.json`, `composer.lock`
  committed. Any dependency change is its own commit and needs approval first. No blanket
  upgrades.
- **DB migration rule**: Every schema change goes through a migration file. Never edit a
  migration that has been applied/committed - add a new one. Trigger and constraint DDL counts
  as schema and follows the same rule.
- **Immutability is sacred**: No code path may UPDATE or DELETE `ledger_entries` or
  `ledger_postings` rows, including tests (tests that need "corruption" plant it via snapshots
  or raw inserts of new rows, never by mutating money rows). The model guards and DB triggers
  are enforcement, not suggestions to work around.

## Error handling & logging

- **Every fallible call handles failure**: Every insert can hit a constraint; the posting path
  catches unique violations for the two documented races (idempotency key, reverses_entry_id)
  and re-raises everything else untouched. No blanket `catch (Throwable)` anywhere.
- **One exception hierarchy**: Every library-raised error extends `LedgerException` and carries
  a stable `errorCode()` from the table in `docs/api-contracts.md`. Driver exceptions the
  library does not own (deadlock, connection loss) pass through unwrapped so the host's retry
  machinery sees the real thing.
- **Friendly vs detailed**: Exception messages are short and safe for host-app logs; details
  (entry id, account codes, fingerprints - never full line contents at error level) go into
  exception context and structured logs.
- **Structured logging from day one**: The package logs through the host's PSR-3 logger with
  dotted event keys: `ledger.entry_posted`, `ledger.entry_replayed`,
  `ledger.idempotency_conflict`, `ledger.entry_reversed`, `ledger.snapshot_built`,
  `ledger.verify_passed`, `ledger.verify_failed`. Context arrays, no interpolated strings.
- **Commands never stack-trace**: `ledger:*` commands catch `LedgerException`, print
  `code: message`, and exit nonzero; unexpected exceptions still surface (they are bugs).

## Security

- **No secrets in the package**: The package never reads credentials; it uses the host's named
  connection. `.env.example` in this repo exists only for the test suite's database DSNs and
  carries dummies.
- **Parameterized queries only**: Eloquent / query builder bindings everywhere. The only raw SQL
  is migration DDL (no user input) and aggregate `SUM` selects with bound parameters.
- **Input validation at the boundary**: Account codes, currencies, amounts, metadata lengths are
  validated in `EntryDraft`/`LedgerService` before SQL, with the database constraints as the
  backstop, never the only stop.
- **No dynamic identifiers**: Table and column names come from constants, never from caller
  input; the config exposes no table-name override in v1 precisely to keep DDL static.

## Simplicity / YAGNI-KISS

- Build only what the current phase requires. No account hierarchies, no multi-tenant scoping,
  no currency conversion, no event hooks in v1; Backlog them in `docs/phases.md` instead.
- Config stays at the three documented keys (`connection`, `snapshot.safety_window_seconds`,
  `snapshot.min_tail`). A new config key needs owner approval.
- No abstraction until three real use cases exist. There is one ledger implementation; no
  `LedgerInterface`, no repository layer, no manager classes.
- Prefer the database's mechanism (unique index, FK, trigger) over application bookkeeping when
  both can express an invariant; the DB one survives bypasses.
- If a solution exceeds ~150 lines, pause and justify it before continuing.

## Code style

- Comments are sparse and explain why, not what. The snapshot safety window, the deferred
  trigger, and the fingerprint canonical form deserve doc blocks; getters do not.
- No emoji anywhere in code, comments, commits, or docs.
- No mention of AI, assistants, or authorship attribution anywhere - no "Generated by", no
  `Co-authored-by` lines in commits.
- Conventional Commits, imperative subject, one per feature.
- Let Pint own formatting; do not hand-format against it.

## Boundaries - never do without asking the owner first

- **No wholesale delete/rewrite** of working files. Targeted edits; flag destructive changes.
- **Do not change `docs/PRD.md` or `docs/architecture.md`** without flagging the change and its
  reason and getting sign-off - they are the source of truth.
- **No new dependency without approval.** Propose what, why, version, and size, then wait.
- **Ask when ambiguous** rather than guessing at ledger semantics; wrong money code is worse
  than late money code.
- **Stop after two failed fix attempts** on the same problem; report what was tried instead of
  thrashing.
- **Scope discipline**: any mid-phase request not in `docs/PRD.md` gets classified with the
  owner as (a) current phase, (b) a new phase, or (c) Backlog in `docs/phases.md`. Never
  silently absorb scope.
