<?php

namespace LedgerKit;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LedgerKit\Exceptions\InvalidAmountException;
use LedgerKit\Exceptions\InvalidEntryException;
use LedgerKit\Exceptions\UnbalancedEntryException;
use LedgerKit\Exceptions\UnknownAccountException;
use LedgerKit\Models\Account;

/**
 * Immutable builder for one balanced entry. Every mutator returns a new draft,
 * so a half-built draft can be shared or reused without surprises.
 */
final class EntryDraft
{
    private const MAX_DESCRIPTION = 255;

    private const MAX_EXTERNAL_REF = 191;

    private const MAX_ACTOR = 191;

    /**
     * ISO 4217 minor unit exponents that are not 2. Anything unlisted is 2,
     * which covers the overwhelming majority of circulating currencies.
     *
     * @var array<string, int>
     */
    private const MINOR_UNIT_EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0,
        'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3,
        'TND' => 3,
        'CLF' => 4, 'UYW' => 4,
    ];

    /**
     * @param  list<array{account: Account|string, amount: int|string|float, sign: int}>  $lines
     */
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly array $lines = [],
        private readonly ?string $description = null,
        private readonly ?string $externalRef = null,
        private readonly ?string $actor = null,
        private readonly ?DateTimeInterface $postedAt = null,
        private readonly ?string $reversesEntryId = null,
    ) {}

    /**
     * A debit stores a positive amount. Passing a negative amount therefore
     * flips the side, which is what makes a reversal a plain negation.
     *
     * @param  int|string  $amount  Minor units, or an exact decimal string such as '12.50'.
     *                              Floats are accepted by the signature only so they can be
     *                              rejected with invalid_amount instead of a TypeError.
     */
    public function debit(Account|string $account, int|string|float $amount): self
    {
        return $this->withLine($account, $amount, 1);
    }

    /**
     * A credit stores a negative amount; see debit() for the amount contract.
     *
     * @param  int|string  $amount
     */
    public function credit(Account|string $account, int|string|float $amount): self
    {
        return $this->withLine($account, $amount, -1);
    }

    public function describe(string $description): self
    {
        $this->assertMetadata($description, self::MAX_DESCRIPTION, 'description');

        return new self($this->ledger, $this->lines, $description, $this->externalRef, $this->actor, $this->postedAt, $this->reversesEntryId);
    }

    public function externalRef(string $ref): self
    {
        $this->assertMetadata($ref, self::MAX_EXTERNAL_REF, 'external_ref');

        return new self($this->ledger, $this->lines, $this->description, $ref, $this->actor, $this->postedAt, $this->reversesEntryId);
    }

    public function actor(string $actor): self
    {
        $this->assertMetadata($actor, self::MAX_ACTOR, 'actor');

        return new self($this->ledger, $this->lines, $this->description, $this->externalRef, $actor, $this->postedAt, $this->reversesEntryId);
    }

    /** Business effective time; drives as-of queries. Defaults to now. */
    public function postedAt(DateTimeInterface $at): self
    {
        return new self($this->ledger, $this->lines, $this->description, $this->externalRef, $this->actor, $at, $this->reversesEntryId);
    }

    /**
     * Marks the draft as the reversal of an entry. The unique index on
     * reverses_entry_id is what makes at most one reversal per entry true.
     *
     * @internal Use Ledger::reverse().
     */
    public function reversing(string $entryId): self
    {
        return new self($this->ledger, $this->lines, $this->description, $this->externalRef, $this->actor, $this->postedAt, $entryId);
    }

    /**
     * Runs every PHP side check and returns the exact rows to insert. No write
     * SQL has run when this returns, so a rejected draft persists nothing.
     *
     * @internal
     *
     * @return array{entry: array<string, mixed>, postings: list<array<string, mixed>>}
     *
     * @throws UnbalancedEntryException|InvalidAmountException|UnknownAccountException
     */
    public function compile(): array
    {
        if (count($this->lines) < 2) {
            throw UnbalancedEntryException::tooFewLines(count($this->lines));
        }

        $accounts = $this->resolveAccounts();

        $now = Carbon::now();
        $postedAt = $this->postedAt !== null ? Carbon::instance($this->postedAt) : $now;
        $entryId = (string) Str::ulid();

        $postings = [];
        $sums = [];

        foreach ($this->lines as $index => $line) {
            $account = $accounts[$index];
            $amount = $line['sign'] * $this->toMinorUnits($line['amount'], $account->currency);

            $postings[] = [
                'entry_id' => $entryId,
                'account_id' => $account->id,
                'currency' => $account->currency,
                'amount' => $amount,
                'posted_at' => $postedAt,
                'created_at' => $now,
            ];

            // bcmath, not +=: a sum of two int64 amounts can leave PHP's int range.
            $sums[$account->currency] = bcadd($sums[$account->currency] ?? '0', (string) $amount);
        }

        foreach ($sums as $currency => $sum) {
            if (bccomp($sum, '0') !== 0) {
                throw UnbalancedEntryException::currency($currency, $sum);
            }
        }

        return [
            'entry' => [
                'id' => $entryId,
                'description' => $this->description,
                'external_ref' => $this->externalRef,
                'actor' => $this->actor,
                'reverses_entry_id' => $this->reversesEntryId,
                'posted_at' => $postedAt,
                'created_at' => $now,
            ],
            'postings' => $postings,
        ];
    }

    private function withLine(Account|string $account, int|string|float $amount, int $sign): self
    {
        $lines = [...$this->lines, ['account' => $account, 'amount' => $amount, 'sign' => $sign]];

        return new self($this->ledger, $lines, $this->description, $this->externalRef, $this->actor, $this->postedAt, $this->reversesEntryId);
    }

    /**
     * Every referenced code in one query; a model handed back to us is trusted
     * only once it is known to exist.
     *
     * @return array<int, Account>
     *
     * @throws UnknownAccountException
     */
    private function resolveAccounts(): array
    {
        $codes = [];

        foreach ($this->lines as $line) {
            if (is_string($line['account'])) {
                $codes[$line['account']] = true;
            }
        }

        $found = $codes === []
            ? []
            : Account::query()->whereIn('code', array_keys($codes))->get()->keyBy('code')->all();

        $resolved = [];

        foreach ($this->lines as $index => $line) {
            $account = $line['account'];

            if ($account instanceof Account) {
                if (! $account->exists) {
                    throw UnknownAccountException::forCode((string) $account->code);
                }

                $resolved[$index] = $account;

                continue;
            }

            if (! isset($found[$account])) {
                throw UnknownAccountException::forCode($account);
            }

            $resolved[$index] = $found[$account];
        }

        return $resolved;
    }

    /**
     * Minor units are the only stored form. Decimal strings convert here once
     * with bcmath against the currency exponent; sub-minor precision is a
     * caller bug and is refused rather than rounded away.
     *
     * @throws InvalidAmountException
     */
    private function toMinorUnits(int|string|float $amount, string $currency): int
    {
        if (is_float($amount)) {
            throw InvalidAmountException::floatInput($currency);
        }

        $value = is_int($amount)
            ? $this->assertIntegerRange((string) $amount, $currency)
            : $this->convertDecimalString($amount, $currency);

        if ($value === 0) {
            throw InvalidAmountException::zero($currency);
        }

        return $value;
    }

    /**
     * @throws InvalidAmountException
     */
    private function convertDecimalString(string $amount, string $currency): int
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw InvalidAmountException::format($currency);
        }

        $exponent = self::MINOR_UNIT_EXPONENTS[$currency] ?? 2;
        $factor = bcpow('10', (string) $exponent);

        $fraction = strrchr($amount, '.');
        $places = $fraction === false ? 0 : strlen($fraction) - 1;
        $scale = max($places - $exponent, 0);

        $exact = bcmul($amount, $factor, $scale);
        $truncated = bcmul($amount, $factor, 0);

        if (bccomp($exact, $truncated, $scale) !== 0) {
            throw InvalidAmountException::precision($currency, $exponent);
        }

        return $this->assertIntegerRange($truncated, $currency);
    }

    /**
     * Bounds the magnitude by PHP_INT_MAX rather than PHP_INT_MIN so negating
     * for the credit side, or for a reversal, can never overflow.
     *
     * @throws InvalidAmountException
     */
    private function assertIntegerRange(string $value, string $currency): int
    {
        if (bccomp($value, (string) PHP_INT_MAX, 0) > 0 || bccomp($value, '-'.PHP_INT_MAX, 0) < 0) {
            throw InvalidAmountException::outOfRange($currency);
        }

        return (int) $value;
    }

    /**
     * @throws InvalidEntryException
     */
    private function assertMetadata(string $value, int $max, string $field): void
    {
        if ($value === '' || mb_strlen($value) > $max) {
            throw InvalidEntryException::metadataLength($field, $max);
        }
    }
}
