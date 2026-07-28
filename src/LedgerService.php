<?php

namespace LedgerKit;

use Illuminate\Database\QueryException;
use LedgerKit\Exceptions\InvalidAccountException;
use LedgerKit\Exceptions\UnknownAccountException;
use LedgerKit\Models\Account;

class LedgerService
{
    private const CODE_PATTERN = '/^[a-z0-9._-]{1,64}$/';

    private const CURRENCY_PATTERN = '/^[A-Z]{3}$/';

    /**
     * @throws InvalidAccountException
     */
    public function createAccount(string $code, string $name, AccountType $type, string $currency): Account
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw InvalidAccountException::code($code);
        }

        if ($name === '' || mb_strlen($name) > 255) {
            throw InvalidAccountException::name($code);
        }

        if (preg_match(self::CURRENCY_PATTERN, $currency) !== 1) {
            throw InvalidAccountException::currency($code, $currency);
        }

        try {
            return Account::query()->create([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'currency' => $currency,
            ]);
        } catch (QueryException $e) {
            // Let the unique index decide the duplicate, not a read before it.
            if ($this->isUniqueViolation($e)) {
                throw InvalidAccountException::duplicateCode($code, $e);
            }

            throw $e;
        }
    }

    /**
     * @throws UnknownAccountException
     */
    public function account(string $code): Account
    {
        $account = Account::query()->where('code', $code)->first();

        if ($account === null) {
            throw UnknownAccountException::forCode($code);
        }

        return $account;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        return $e->getCode() === '23505' || $driverCode === 1062;
    }
}
