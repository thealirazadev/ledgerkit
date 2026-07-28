<?php

namespace LedgerKit\Models;

use Illuminate\Database\Eloquent\Model;
use LedgerKit\AccountType;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property string $currency
 */
class Account extends Model
{
    protected $table = 'ledger_accounts';

    /** Code, type, and currency are write once; only the name is editable. */
    protected $fillable = ['code', 'name', 'type', 'currency'];

    protected $casts = ['type' => AccountType::class];

    public function getConnectionName(): ?string
    {
        return config('ledgerkit.connection');
    }
}
