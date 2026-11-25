<?php

use LedgerKit\AccountType;
use LedgerKit\Exceptions\InvalidAmountException;
use LedgerKit\Exceptions\InvalidEntryException;
use LedgerKit\Exceptions\UnbalancedEntryException;
use LedgerKit\Exceptions\UnknownAccountException;
use LedgerKit\Facades\Ledger;
use LedgerKit\Models\Account;

function draftAccounts(): void
{
    Ledger::createAccount('cash.usd', 'Cash USD', AccountType::Asset, 'USD');
    Ledger::createAccount('revenue.usd', 'Revenue USD', AccountType::Income, 'USD');
    Ledger::createAccount('cash.eur', 'Cash EUR', AccountType::Asset, 'EUR');
    Ledger::createAccount('revenue.eur', 'Revenue EUR', AccountType::Income, 'EUR');
    Ledger::createAccount('cash.jpy', 'Cash JPY', AccountType::Asset, 'JPY');
    Ledger::createAccount('revenue.jpy', 'Revenue JPY', AccountType::Income, 'JPY');
    Ledger::createAccount('cash.kwd', 'Cash KWD', AccountType::Asset, 'KWD');
    Ledger::createAccount('revenue.kwd', 'Revenue KWD', AccountType::Income, 'KWD');
}

/**
 * @return list<int>
 */
function compiledAmounts(array $compiled): array
{
    return array_map(fn (array $posting) => $posting['amount'], $compiled['postings']);
}

function amountFailureReason(Closure $build): ?string
{
    try {
        $build()->compile();
    } catch (InvalidAmountException $e) {
        expect($e->errorCode())->toBe('invalid_amount');

        return $e->context()['reason'];
    }

    return null;
}

it('compiles a balanced entry with debits positive and credits negative', function () {
    draftAccounts();

    $compiled = Ledger::entry()
        ->debit('cash.usd', 10_000)
        ->credit('revenue.usd', 10_000)
        ->compile();

    expect(compiledAmounts($compiled))->toBe([10_000, -10_000])
        ->and(array_column($compiled['postings'], 'currency'))->toBe(['USD', 'USD'])
        ->and($compiled['postings'][0]['entry_id'])->toBe($compiled['entry']['id'])
        ->and($compiled['entry']['id'])->toHaveLength(26);
});

it('flips the side of a negative amount', function () {
    draftAccounts();

    $compiled = Ledger::entry()
        ->debit('cash.usd', -500)
        ->credit('revenue.usd', -500)
        ->compile();

    expect(compiledAmounts($compiled))->toBe([-500, 500]);
});

it('accepts account models as well as codes', function () {
    draftAccounts();

    $cash = Ledger::account('cash.usd');

    $compiled = Ledger::entry()->debit($cash, 1)->credit('revenue.usd', 1)->compile();

    expect($compiled['postings'][0]['account_id'])->toBe($cash->id);
});

it('carries metadata and posted_at onto the entry and every posting', function () {
    draftAccounts();

    $at = new DateTimeImmutable('2026-03-01 09:30:00');

    $compiled = Ledger::entry()
        ->debit('cash.usd', 100)
        ->credit('revenue.usd', 100)
        ->describe('invoice 4411 paid')
        ->externalRef('invoice:4411')
        ->actor('system:billing')
        ->postedAt($at)
        ->compile();

    expect($compiled['entry']['description'])->toBe('invoice 4411 paid')
        ->and($compiled['entry']['external_ref'])->toBe('invoice:4411')
        ->and($compiled['entry']['actor'])->toBe('system:billing')
        ->and($compiled['entry']['posted_at']->toDateTimeString())->toBe('2026-03-01 09:30:00');

    foreach ($compiled['postings'] as $posting) {
        expect($posting['posted_at']->toDateTimeString())->toBe('2026-03-01 09:30:00');
    }
});

it('leaves the original draft untouched when a mutator is called', function () {
    draftAccounts();

    $base = Ledger::entry()->debit('cash.usd', 100);
    $complete = $base->credit('revenue.usd', 100);

    expect($complete)->not->toBe($base)
        ->and(fn () => $base->compile())->toThrow(UnbalancedEntryException::class);

    expect(compiledAmounts($complete->compile()))->toBe([100, -100]);
});

it('records the reversed entry id only on a reversal draft', function () {
    draftAccounts();

    $draft = Ledger::entry()->debit('cash.usd', 500)->credit('revenue.usd', 500);
    $reversal = $draft->reversing('01JD0000000000000000000000');

    expect($draft->compile()['entry']['reverses_entry_id'])->toBeNull()
        ->and($reversal->compile()['entry']['reverses_entry_id'])->toBe('01JD0000000000000000000000');
});

it('rejects an entry with fewer than two lines', function (int $lines) {
    draftAccounts();

    $draft = Ledger::entry();

    for ($i = 0; $i < $lines; $i++) {
        $draft = $draft->debit('cash.usd', 100);
    }

    try {
        $draft->compile();
        $this->fail('Expected a short entry to be rejected.');
    } catch (UnbalancedEntryException $e) {
        expect($e->errorCode())->toBe('unbalanced_entry')
            ->and($e->context())->toBe(['line_count' => $lines]);
    }
})->with([0, 1]);

it('rejects an entry that does not sum to zero', function () {
    draftAccounts();

    try {
        Ledger::entry()->debit('cash.usd', 1_000)->credit('revenue.usd', 500)->compile();
        $this->fail('Expected an unbalanced entry to be rejected.');
    } catch (UnbalancedEntryException $e) {
        expect($e->context())->toBe(['currency' => 'USD', 'sum' => '500']);
    }
});

it('balances each currency on its own', function () {
    draftAccounts();

    $compiled = Ledger::entry()
        ->debit('cash.usd', 100)
        ->credit('revenue.usd', 100)
        ->debit('cash.eur', 90)
        ->credit('revenue.eur', 90)
        ->compile();

    expect(compiledAmounts($compiled))->toBe([100, -100, 90, -90]);

    // Zero overall, but USD and EUR each carry a residue.
    expect(fn () => Ledger::entry()->debit('cash.usd', 100)->credit('revenue.eur', 100)->compile())
        ->toThrow(UnbalancedEntryException::class);
});

it('converts decimal strings with the currency exponent', function (string $currency, string $amount, int $minor) {
    draftAccounts();

    $code = strtolower($currency);

    $compiled = Ledger::entry()
        ->debit("cash.{$code}", $amount)
        ->credit("revenue.{$code}", $amount)
        ->compile();

    expect(compiledAmounts($compiled))->toBe([$minor, -$minor]);
})->with([
    'USD two places' => ['USD', '12.50', 1250],
    'USD whole' => ['USD', '7', 700],
    'USD trailing zero beyond precision' => ['USD', '12.500', 1250],
    'JPY no minor unit' => ['JPY', '1500', 1500],
    'KWD three places' => ['KWD', '1.234', 1234],
    'negative string' => ['USD', '-0.25', -25],
]);

it('rejects a float amount', function () {
    draftAccounts();

    expect(amountFailureReason(fn () => Ledger::entry()->debit('cash.usd', 1.5)->credit('revenue.usd', 150)))
        ->toBe('float');
});

it('rejects a zero amount', function (int|string $zero) {
    draftAccounts();

    expect(amountFailureReason(fn () => Ledger::entry()->debit('cash.usd', $zero)->credit('revenue.usd', 100)))
        ->toBe('zero');
})->with([0, '0', '0.00', '-0.00']);

it('rejects a malformed amount string', function (string $amount) {
    draftAccounts();

    expect(amountFailureReason(fn () => Ledger::entry()->debit('cash.usd', $amount)->credit('revenue.usd', 100)))
        ->toBe('format');
})->with([
    'empty' => '',
    'leading plus' => '+5',
    'leading dot' => '.5',
    'trailing dot' => '5.',
    'exponent' => '1e3',
    'thousands separator' => '1,000',
    'surrounding space' => ' 5',
    'trailing newline' => "12.50\n",
]);

it('rejects precision finer than the minor unit', function (string $code, string $amount) {
    draftAccounts();

    expect(amountFailureReason(fn () => Ledger::entry()->debit($code, $amount)->credit($code, $amount)))
        ->toBe('precision');
})->with([
    'USD three places' => ['cash.usd', '12.505'],
    'JPY any fraction' => ['cash.jpy', '100.5'],
    'KWD four places' => ['cash.kwd', '1.2345'],
]);

it('rejects an amount outside the signed 64 bit range', function (int|string $amount) {
    draftAccounts();

    expect(amountFailureReason(fn () => Ledger::entry()->debit('cash.usd', $amount)->credit('revenue.usd', 100)))
        ->toBe('out_of_range');
})->with([
    'decimal one past max' => '92233720368547758.08',
    'int min' => PHP_INT_MIN,
]);

it('accepts the largest amount that can still be negated', function () {
    draftAccounts();

    $compiled = Ledger::entry()
        ->debit('cash.usd', PHP_INT_MAX)
        ->credit('revenue.usd', '92233720368547758.07')
        ->compile();

    expect(compiledAmounts($compiled))->toBe([PHP_INT_MAX, -PHP_INT_MAX]);
});

it('rejects an account code that does not resolve', function () {
    draftAccounts();

    try {
        Ledger::entry()->debit('cash.usd', 100)->credit('missing.account', 100)->compile();
        $this->fail('Expected an unknown account to be rejected.');
    } catch (UnknownAccountException $e) {
        expect($e->context())->toBe(['code' => 'missing.account']);
    }
});

it('rejects an account model that was never saved', function () {
    draftAccounts();

    $unsaved = new Account(['code' => 'ghost.usd', 'name' => 'Ghost', 'type' => AccountType::Asset, 'currency' => 'USD']);

    expect(fn () => Ledger::entry()->debit($unsaved, 100)->credit('revenue.usd', 100)->compile())
        ->toThrow(UnknownAccountException::class);
});

it('rejects empty or oversized metadata', function (string $method, int $max) {
    $draft = Ledger::entry();

    foreach (['', str_repeat('m', $max + 1)] as $value) {
        try {
            $draft->{$method}($value);
            $this->fail("Expected {$method}() to reject a value of length ".mb_strlen($value).'.');
        } catch (InvalidEntryException $e) {
            expect($e->errorCode())->toBe('invalid_entry')
                ->and($e->context()['max'])->toBe($max);
        }
    }

    expect($draft->{$method}(str_repeat('m', $max)))->toBeInstanceOf($draft::class);
})->with([
    'description' => ['describe', 255],
    'external ref' => ['externalRef', 191],
    'actor' => ['actor', 191],
]);
