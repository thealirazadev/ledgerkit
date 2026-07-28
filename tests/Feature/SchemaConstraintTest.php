<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LedgerKit\AccountType;
use LedgerKit\Facades\Ledger;

/**
 * These tests bypass the library on purpose: their subject is what the database
 * does when something writes money rows without going through LedgerService.
 */
function rawAccount(array $overrides = []): void
{
    DB::table('ledger_accounts')->insert(array_merge([
        'code' => 'raw.'.Str::random(8),
        'name' => 'Raw account',
        'type' => 'asset',
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function rawEntry(): string
{
    $id = (string) Str::ulid();

    DB::table('ledger_entries')->insert([
        'id' => $id,
        'posted_at' => now(),
        'created_at' => now(),
    ]);

    return $id;
}

function rawPosting(array $overrides = []): void
{
    DB::table('ledger_postings')->insert(array_merge([
        'entry_id' => rawEntry(),
        'currency' => 'USD',
        'amount' => 100,
        'posted_at' => now(),
        'created_at' => now(),
    ], $overrides));
}

it('rejects a raw account row with a malformed currency', function (string $currency) {
    expect(fn () => rawAccount(['currency' => $currency]))->toThrow(QueryException::class);
})->with([
    'lowercase' => 'usd',
    'two letters' => 'US',
    'digits' => 'US1',
]);

it('rejects a raw account row with an unknown type', function () {
    expect(fn () => rawAccount(['type' => 'revenue']))->toThrow(QueryException::class);
});

it('accepts a raw account row that satisfies both checks', function () {
    rawAccount(['code' => 'raw.valid', 'type' => 'liability', 'currency' => 'EUR']);

    expect(DB::table('ledger_accounts')->where('code', 'raw.valid')->count())->toBe(1);
});

it('rejects a raw posting whose currency differs from its account', function () {
    $account = Ledger::createAccount('cash.main', 'Main cash', AccountType::Asset, 'USD');

    expect(fn () => rawPosting(['account_id' => $account->id, 'currency' => 'EUR']))
        ->toThrow(QueryException::class);
});

it('rejects a raw posting with a zero amount', function () {
    $account = Ledger::createAccount('cash.main', 'Main cash', AccountType::Asset, 'USD');

    expect(fn () => rawPosting(['account_id' => $account->id, 'amount' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects a raw posting against an account that does not exist', function () {
    expect(fn () => rawPosting(['account_id' => 987654321]))->toThrow(QueryException::class);
});

it('accepts a raw posting in the account currency', function () {
    $account = Ledger::createAccount('cash.main', 'Main cash', AccountType::Asset, 'USD');

    rawPosting(['account_id' => $account->id, 'amount' => -2500]);

    expect(DB::table('ledger_postings')->sum('amount'))->toEqual(-2500);
});

it('rejects a second entry with the same idempotency key', function () {
    $insert = fn () => DB::table('ledger_entries')->insert([
        'id' => (string) Str::ulid(),
        'idempotency_key' => 'invoice-4411',
        'posted_at' => now(),
        'created_at' => now(),
    ]);

    $insert();

    expect($insert)->toThrow(QueryException::class);
});

it('rejects a second entry reversing the same entry', function () {
    $original = rawEntry();

    $insert = fn () => DB::table('ledger_entries')->insert([
        'id' => (string) Str::ulid(),
        'reverses_entry_id' => $original,
        'posted_at' => now(),
        'created_at' => now(),
    ]);

    $insert();

    expect($insert)->toThrow(QueryException::class);
});
