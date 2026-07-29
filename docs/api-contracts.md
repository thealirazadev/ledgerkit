# API Contracts - ledgerkit

ledgerkit has no HTTP surface. Its contracts are three things, fixed here before any code is
written: the **PHP public API** (the `Ledger` facade, result objects, and exceptions), the
**artisan commands**, and the **database schema** (fixed in `docs/architecture.md`; the table
and column names are part of the public contract because operators will query them).

Everything not listed here is `@internal`.

## Error format

Every error the library raises extends `LedgerKit\Exceptions\LedgerException`:

```php
abstract class LedgerException extends \RuntimeException
{
    /** Stable machine-readable code from the table below. Never changes meaning. */
    abstract public function errorCode(): string;

    /** Structured context (ids, codes, fingerprints). Never full posting lines. */
    public function context(): array;
}
```

Commands print the same taxonomy as `error [<code>]: <message>` on stderr and exit nonzero.

### Stable error codes

| `errorCode()` | Exception | When |
|---|---|---|
| `unbalanced_entry` | `UnbalancedEntryException` | Per-currency posting sums are not all zero, or fewer than 2 lines. |
| `invalid_amount` | `InvalidAmountException` | Zero amount, float input, non-integer string, or sub-minor-unit precision. |
| `unknown_account` | `UnknownAccountException` | Account code or id does not resolve. |
| `currency_mismatch` | `CurrencyMismatchException` | Line currency differs from account currency, or transfer across currencies. |
| `idempotency_conflict` | `IdempotencyConflictException` | Same idempotency key, different economic content. |
| `already_reversed` | `AlreadyReversedException` | Entry already has a reversing entry (including lost races). |
| `immutable_record` | `ImmutableRecordException` | `update`/`delete` attempted on an `Entry`/`Posting` model. |
| `unsupported_driver` | `UnsupportedDriverException` | Migration run on a driver other than pgsql/mysql. |
| `invalid_account` | `InvalidAccountException` | Bad code format/length, bad currency format, duplicate code, bad type. |
| `invalid_entry` | `InvalidEntryException` | Entry metadata (description, external_ref, actor) empty or over its column width; entry id does not resolve. |

Driver-level exceptions (deadlocks, connection loss, constraint violations the library does not
own) pass through unwrapped; ledgerkit only catches the two documented unique-violation races.

---

## PHP public API

All methods live on `LedgerKit\LedgerService`, exposed via the `Ledger` facade. All money
parameters are `int` minor units or exact decimal strings; floats always throw `invalid_amount`.

### Accounts

```php
Ledger::createAccount(
    string $code,           // 1-64 chars, [a-z0-9._-], unique
    string $name,           // 1-255 chars
    AccountType $type,      // AccountType::Asset|Liability|Income|Expense|Equity
    string $currency,       // ISO 4217 uppercase, e.g. 'USD'
): Account;

Ledger::account(string $code): Account;   // throws unknown_account
```

```php
$cash = Ledger::createAccount('cash.main', 'Main cash', AccountType::Asset, 'USD');
```

Throws: `invalid_account` (format, duplicate code), `unknown_account` (lookup miss).
`Account->name` is the only mutable field; code, type, and currency are write-once.

### Posting an entry

```php
Ledger::entry(): EntryDraft;

// EntryDraft (immutable builder; each call returns a new draft):
->debit(Account|string $account, int|string $amount): EntryDraft
->credit(Account|string $account, int|string $amount): EntryDraft
->describe(string $description): EntryDraft            // max 255
->externalRef(string $ref): EntryDraft                 // max 191, indexed
->actor(string $actor): EntryDraft                     // max 191, e.g. 'user:42'
->postedAt(DateTimeInterface $at): EntryDraft          // business time; default now
->withIdempotencyKey(string $key): EntryDraft          // max 191
->post(): PostedEntry
```

```php
$result = Ledger::entry()
    ->debit('cash.main', 10_000)          // +100.00 USD
    ->credit('revenue.subscriptions', 10_000)
    ->describe('invoice 4411 paid')
    ->externalRef('invoice:4411')
    ->actor('system:billing')
    ->withIdempotencyKey('invoice-4411-payment')
    ->post();

$result->entry;      // Entry model (ULID id, metadata, posted_at)
$result->postings;   // Collection<Posting>
$result->replayed;   // false on first post, true when the key already existed
```

Semantics:
- `debit` stores a positive amount, `credit` a negative one; per-currency sums must be zero.
- Amounts as strings are exact decimals for the account's currency (`'12.50'` for USD);
  precision beyond the minor unit throws `invalid_amount`.
- The entry and all postings insert in one transaction; there is no partial state.
- With an idempotency key, a retry with identical economic content (same account/amount/
  currency lines in any order) returns the original entry with `replayed = true`; changed
  content throws `idempotency_conflict`. Description, actor, external_ref, and posted_at are
  **not** part of the fingerprint.

Throws: `unbalanced_entry`, `invalid_amount`, `unknown_account`, `currency_mismatch`,
`idempotency_conflict`.

### Transfers

```php
Ledger::transfer(
    Account|string $from,
    Account|string $to,
    int|string $amount,
    ?string $description = null,
    ?string $externalRef = null,
    ?string $actor = null,
    ?string $idempotencyKey = null,
): PostedEntry;
```

Builds the two-line entry `credit $from / debit $to`. Both accounts must share a currency, else
`currency_mismatch`. Identical idempotency semantics to `post()`.

```php
Ledger::transfer('wallet.user42', 'wallet.user77', 2_500, idempotencyKey: 'tip-9081');
```

### Guarded transfers (cooperative locking)

```php
Ledger::transferGuarded(
    Account|string $from,
    Account|string $to,
    int|string $amount,
    Closure $guard,          // fn (int $fromBalance): void  - throw to abort
    ...same optional metadata/idempotency parameters as transfer()
): PostedEntry;
```

Inside one transaction: locks the `$from` account row (`SELECT ... FOR UPDATE`), computes the
full balance under the lock, calls `$guard($balance)`, and posts only if the guard returns. A
guard throw rolls everything back and propagates unchanged.

```php
Ledger::transferGuarded('wallet.user42', 'fees.platform', 500,
    guard: function (int $balance) {
        if ($balance < 500) {
            throw new InsufficientFundsException();  // caller's own exception
        }
    },
);
```

**Contract**: the lock serializes only writers that take it. Every debit of an account that must
respect a floor has to go through `transferGuarded` (or take the same lock); lock-free `post()`
calls are not serialized against it. This is by design; see `docs/architecture.md`.

### Reversal

```php
Ledger::reverse(
    Entry|string $entry,               // model or ULID
    ?string $description = null,       // default: 'reversal of {id}'
    ?string $actor = null,
): PostedEntry;
```

Posts a new entry with every posting negated and `reverses_entry_id` set. The original is
untouched. At most one reversal per entry ever succeeds; a second call (or a lost concurrent
race) throws `already_reversed`. Reversals themselves can be reversed (a new entry reversing
the reversing entry), which re-instates the original effect.

### Balances

```php
Ledger::balance(Account|string $account): int;                          // current
Ledger::balanceAsOf(Account|string $account, DateTimeInterface $at): int;
```

Both return the signed, debit-positive sum in minor units: asset/expense accounts normally read
positive, liability/income/equity normally read negative; callers negate for presentation if
they prefer credit-normal display. `balance()` may use the snapshot table (identical result,
faster); `balanceAsOf()` always recomputes over `posted_at <= $at`, so backdated entries land
in the period their `posted_at` claims.

### Snapshots

```php
app(SnapshotBuilder::class)->build(Account|string $account): ?BalanceSnapshot;
```

Returns the new snapshot, or null when nothing qualified (tail shorter than
`snapshot.min_tail`, or no postings older than the safety window past the last boundary).
Locked, idempotent, append-only; normally invoked via `ledger:snapshot`.

### Read models

`Account`, `Entry`, `Posting`, `BalanceSnapshot` are Eloquent models safe to query freely
(relations: `Entry hasMany Posting`, `Entry belongsTo reversedEntry`, `Account hasMany
Posting`). Calling `update`/`delete`/`save` on `Entry` or `Posting` throws `immutable_record`
before SQL; the database triggers back this at the engine level.

---

## Artisan commands

Shared conventions: first output line names the connection and database; exit 0 success, 1
verification/invariant failure, 2 usage error; errors as `error [<code>]: <message>` on stderr.
Full output shapes in `docs/design.md`.

### ledger:verify

```
php artisan ledger:verify [--account=<code>]
```

Read-only. Re-proves from stored rows: every entry has >= 2 postings and zero per-currency
sums; posting `posted_at` matches its entry; every latest snapshot's balance and posting_count
equal a full recomputation up to its boundary. `--account=` restricts the snapshot pass. Exit
0 clean; exit 1 with one line per violation:

```
FAIL entry 01J8Z... currency USD sum 500 (expected 0)
FAIL snapshot 14 account cash.main stored 1204500 recomputed 1204000
```

### ledger:snapshot

```
php artisan ledger:snapshot [--account=<code>] [--force]
```

Builds at most one new snapshot per qualifying account (see `SnapshotBuilder` contract).
`--force` ignores `snapshot.min_tail`. Prints one line per account (built, skipped and why)
and a summary count. Exit 0 even when nothing was written (skipping is success).

### ledger:demo

```
php artisan ledger:demo [--yes]
```

Writes a scripted scenario (accounts prefixed `demo.`, entries, a transfer, an idempotent
retry, a reversal), prints each step and a final balance table, then runs the verify pass.
Prompts for confirmation naming the target database unless `--yes`. Intended for scratch
databases; safe to re-run (fresh idempotency keys per run). Exit 0 on a clean run including
the embedded verify.

---

## Configuration contract

`config/ledgerkit.php`, publishable; exactly three keys in v1:

| Key | Default | Meaning |
|---|---|---|
| `connection` | `null` | Named database connection; null uses the host default. |
| `snapshot.safety_window_seconds` | `300` | Snapshot boundary excludes postings younger than this. Must exceed the longest write transaction against the ledger tables. |
| `snapshot.min_tail` | `1000` | Skip snapshot builds when fewer postings sit past the last boundary. |

## Logged events

Emitted through the host's PSR-3 logger with context arrays:

| Event | Level | Context |
|---|---|---|
| `ledger.entry_posted` | info | entry_id, line_count, currencies |
| `ledger.entry_replayed` | info | entry_id, idempotency_key |
| `ledger.idempotency_conflict` | warning | idempotency_key, stored_fingerprint, attempted_fingerprint |
| `ledger.entry_reversed` | info | entry_id, reversal_entry_id |
| `ledger.snapshot_built` | info | account_id, boundary, balance, posting_count |
| `ledger.verify_passed` | info | entries_checked, snapshots_checked |
| `ledger.verify_failed` | error | violation list (ids and expected/actual values) |
