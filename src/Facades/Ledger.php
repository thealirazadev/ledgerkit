<?php

namespace LedgerKit\Facades;

use Illuminate\Support\Facades\Facade;
use LedgerKit\LedgerService;

/**
 * @method static \LedgerKit\Models\Account createAccount(string $code, string $name, \LedgerKit\AccountType $type, string $currency)
 * @method static \LedgerKit\Models\Account account(string $code)
 * @method static \LedgerKit\EntryDraft entry()
 *
 * @see LedgerService
 */
class Ledger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LedgerService::class;
    }
}
