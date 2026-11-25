<?php

namespace LedgerKit\Models;

use Illuminate\Database\Eloquent\Model;
use LedgerKit\AccountType;
use LedgerKit\Exceptions\InvalidAccountException;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property string $currency
 */
class Account extends Model
{
    /** @var list<string> */
    private const WRITE_ONCE = ['code', 'type', 'currency'];

    protected $table = 'ledger_accounts';

    /** Code, type, and currency are write once; only the name is editable. */
    protected $fillable = ['code', 'name', 'type', 'currency'];

    protected $casts = ['type' => AccountType::class];

    protected static function booted(): void
    {
        // Postings reference the account by id and currency, and balances are
        // read by code, so changing any of these would rewrite history.
        static::updating(function (Account $account): void {
            foreach (self::WRITE_ONCE as $field) {
                if ($account->isDirty($field)) {
                    throw InvalidAccountException::immutableField((string) $account->getOriginal('code'), $field);
                }
            }
        });
    }

    public function getConnectionName(): ?string
    {
        return config('ledgerkit.connection');
    }
}
