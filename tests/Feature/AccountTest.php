<?php

use LedgerKit\AccountType;
use LedgerKit\Exceptions\InvalidAccountException;
use LedgerKit\Exceptions\UnknownAccountException;
use LedgerKit\Facades\Ledger;
use LedgerKit\Models\Account;

it('creates an account and reads it back by code', function () {
    $created = Ledger::createAccount('cash.main', 'Main cash', AccountType::Asset, 'USD');

    expect($created->code)->toBe('cash.main')
        ->and($created->type)->toBe(AccountType::Asset)
        ->and($created->currency)->toBe('USD');

    $fetched = Ledger::account('cash.main');

    expect($fetched->id)->toBe($created->id)
        ->and($fetched->name)->toBe('Main cash')
        ->and($fetched->type)->toBe(AccountType::Asset);
});

it('accepts every account type', function (AccountType $type) {
    $account = Ledger::createAccount('acct.'.$type->value, 'Account', $type, 'EUR');

    expect($account->type)->toBe($type);
})->with(AccountType::cases());

it('rejects a duplicate account code', function () {
    Ledger::createAccount('cash.main', 'Main cash', AccountType::Asset, 'USD');

    expect(fn () => Ledger::createAccount('cash.main', 'Other cash', AccountType::Asset, 'USD'))
        ->toThrow(InvalidAccountException::class);

    expect(Account::query()->where('code', 'cash.main')->count())->toBe(1);
});

it('reports the duplicate code as invalid_account', function () {
    Ledger::createAccount('cash.main', 'Main cash', AccountType::Asset, 'USD');

    try {
        Ledger::createAccount('cash.main', 'Other cash', AccountType::Asset, 'USD');
        $this->fail('Expected a duplicate code to be rejected.');
    } catch (InvalidAccountException $e) {
        expect($e->errorCode())->toBe('invalid_account')
            ->and($e->context())->toBe(['code' => 'cash.main']);
    }
});

it('rejects an invalid account code', function (string $code) {
    expect(fn () => Ledger::createAccount($code, 'Cash', AccountType::Asset, 'USD'))
        ->toThrow(InvalidAccountException::class);

    expect(Account::query()->count())->toBe(0);
})->with([
    'empty' => '',
    'uppercase' => 'Cash.Main',
    'spaces' => 'cash main',
    'slash' => 'cash/main',
    'too long' => str_repeat('c', 65),
]);

it('rejects an invalid currency', function (string $currency) {
    expect(fn () => Ledger::createAccount('cash.main', 'Cash', AccountType::Asset, $currency))
        ->toThrow(InvalidAccountException::class);

    expect(Account::query()->count())->toBe(0);
})->with([
    'lowercase' => 'usd',
    'two letters' => 'US',
    'four letters' => 'USDX',
    'digits' => 'US1',
    'empty' => '',
]);

it('rejects an empty or oversized account name', function (string $name) {
    expect(fn () => Ledger::createAccount('cash.main', $name, AccountType::Asset, 'USD'))
        ->toThrow(InvalidAccountException::class);
})->with([
    'empty' => '',
    'too long' => str_repeat('n', 256),
]);

it('throws unknown_account when the code does not resolve', function () {
    try {
        Ledger::account('missing.account');
        $this->fail('Expected a missing account to raise.');
    } catch (UnknownAccountException $e) {
        expect($e->errorCode())->toBe('unknown_account')
            ->and($e->context())->toBe(['code' => 'missing.account']);
    }
});
