<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LedgerKit\AccountType;
use LedgerKit\Facades\Ledger;
use LedgerKit\Models\Account;

/**
 * Rows are planted with raw SQL on purpose: a corrupted ledger is exactly what
 * verify exists to catch, and the library will never write one.
 *
 * @param  list<array{0: Account, 1: int, 2?: string}>  $postings  account, amount, optional posted_at
 */
function plantEntry(array $postings, string $postedAt = '2026-01-15 10:00:00'): string
{
    $id = (string) Str::ulid();

    DB::table('ledger_entries')->insert(['id' => $id, 'posted_at' => $postedAt, 'created_at' => $postedAt]);

    foreach ($postings as $posting) {
        DB::table('ledger_postings')->insert([
            'entry_id' => $id,
            'account_id' => $posting[0]->id,
            'currency' => $posting[0]->currency,
            'amount' => $posting[1],
            'posted_at' => $posting[2] ?? $postedAt,
            'created_at' => $postedAt,
        ]);
    }

    return $id;
}

function plantSnapshot(Account $account, int $balance, int $postingCount): int
{
    return (int) DB::table('ledger_balance_snapshots')->insertGetId([
        'account_id' => $account->id,
        'last_posting_id' => (int) DB::table('ledger_postings')->max('id'),
        'balance' => $balance,
        'posting_count' => $postingCount,
        'created_at' => now(),
    ]);
}

/**
 * @return array{0: Account, 1: Account}
 */
function verifyAccounts(): array
{
    return [
        Ledger::createAccount('cash.main', 'Main cash', AccountType::Asset, 'USD'),
        Ledger::createAccount('revenue.main', 'Revenue', AccountType::Income, 'USD'),
    ];
}

it('passes an empty ledger', function () {
    $this->artisan('ledger:verify')
        ->expectsOutputToContain('connection: ')
        ->expectsOutputToContain('verify: clean')
        ->assertExitCode(0);
});

it('passes a balanced ledger with a matching snapshot', function () {
    [$cash, $revenue] = verifyAccounts();

    plantEntry([[$cash, 10_000], [$revenue, -10_000]]);
    plantEntry([[$cash, 2_500], [$revenue, -2_500]]);
    plantSnapshot($cash, 12_500, 2);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('verify: clean')
        ->doesntExpectOutputToContain('FAIL')
        ->assertExitCode(0);
});

it('flags an entry whose postings do not sum to zero', function () {
    [$cash, $revenue] = verifyAccounts();

    $id = plantEntry([[$cash, 1_000], [$revenue, -500]]);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain("FAIL entry {$id} currency USD sum 500 (expected 0)")
        ->assertExitCode(1);
});

it('flags an entry with fewer than two postings', function (int $count) {
    [$cash] = verifyAccounts();

    $id = plantEntry(array_fill(0, $count, [$cash, 100]));

    $this->artisan('ledger:verify')
        ->expectsOutputToContain("FAIL entry {$id} has {$count} posting(s) (expected at least 2)")
        ->assertExitCode(1);
})->with([0, 1]);

it('flags a posting whose posted_at differs from its entry', function () {
    [$cash, $revenue] = verifyAccounts();

    $id = plantEntry([[$cash, 100], [$revenue, -100, '2026-01-16 10:00:00']]);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain("posted_at differs from entry {$id}")
        ->assertExitCode(1);
});

it('flags a snapshot whose balance or count drifted from the postings', function () {
    [$cash, $revenue] = verifyAccounts();

    plantEntry([[$cash, 10_000], [$revenue, -10_000]]);
    $snapshot = plantSnapshot($cash, 9_000, 3);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain("FAIL snapshot {$snapshot} account cash.main stored 9000 recomputed 10000")
        ->expectsOutputToContain("FAIL snapshot {$snapshot} account cash.main stored count 3 recomputed 1")
        ->assertExitCode(1);
});

it('checks only the latest snapshot of each account', function () {
    [$cash, $revenue] = verifyAccounts();

    plantEntry([[$cash, 10_000], [$revenue, -10_000]]);
    plantSnapshot($cash, 1, 1);
    plantEntry([[$cash, 500], [$revenue, -500]]);
    plantSnapshot($cash, 10_500, 2);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('verify: clean')
        ->assertExitCode(0);
});

it('limits the snapshot pass to the account given', function () {
    [$cash, $revenue] = verifyAccounts();

    plantEntry([[$cash, 10_000], [$revenue, -10_000]]);
    plantSnapshot($cash, 10_000, 1);
    plantSnapshot($revenue, 0, 1);

    $this->artisan('ledger:verify', ['--account' => 'cash.main'])
        ->expectsOutputToContain('verify: clean')
        ->assertExitCode(0);

    $this->artisan('ledger:verify', ['--account' => 'revenue.main'])
        ->expectsOutputToContain('account revenue.main stored 0 recomputed -10000')
        ->assertExitCode(1);
});

it('exits with a usage error for an unknown account', function () {
    $this->artisan('ledger:verify', ['--account' => 'missing.account'])
        ->expectsOutputToContain('error [unknown_account]: No ledger account with code [missing.account].')
        ->assertExitCode(2);
});

it('writes nothing', function () {
    [$cash, $revenue] = verifyAccounts();

    plantEntry([[$cash, 1_000], [$revenue, -500]]);

    $before = [
        DB::table('ledger_entries')->count(),
        DB::table('ledger_postings')->count(),
        DB::table('ledger_balance_snapshots')->count(),
    ];

    $this->artisan('ledger:verify')->assertExitCode(1);

    expect([
        DB::table('ledger_entries')->count(),
        DB::table('ledger_postings')->count(),
        DB::table('ledger_balance_snapshots')->count(),
    ])->toBe($before);
});
