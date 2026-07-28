<?php

namespace LedgerKit\Exceptions;

class UnknownAccountException extends LedgerException
{
    public static function forCode(string $code): self
    {
        return new self("No ledger account with code [{$code}].", ['code' => $code]);
    }

    public function errorCode(): string
    {
        return 'unknown_account';
    }
}
