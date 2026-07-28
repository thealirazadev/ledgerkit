<?php

namespace LedgerKit\Exceptions;

use Throwable;

class InvalidAccountException extends LedgerException
{
    public static function code(string $code): self
    {
        return new self(
            'Account code must be 1 to 64 characters of [a-z0-9._-].',
            ['code' => $code]
        );
    }

    public static function name(string $code): self
    {
        return new self(
            'Account name must be 1 to 255 characters.',
            ['code' => $code]
        );
    }

    public static function currency(string $code, string $currency): self
    {
        return new self(
            'Account currency must be a three letter uppercase ISO 4217 code.',
            ['code' => $code, 'currency' => $currency]
        );
    }

    public static function duplicateCode(string $code, ?Throwable $previous = null): self
    {
        return new self(
            "A ledger account with code [{$code}] already exists.",
            ['code' => $code],
            $previous
        );
    }

    public function errorCode(): string
    {
        return 'invalid_account';
    }
}
