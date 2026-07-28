<?php

namespace LedgerKit\Exceptions;

class UnsupportedDriverException extends LedgerException
{
    /** @var list<string> */
    public const SUPPORTED_DRIVERS = ['pgsql', 'mysql'];

    public static function forDriver(string $driver): self
    {
        $supported = implode(', ', self::SUPPORTED_DRIVERS);

        return new self(
            "ledgerkit does not support the [{$driver}] database driver; supported drivers are {$supported}. "
            .'The ledger invariants depend on triggers and row locks other drivers cannot express.',
            ['driver' => $driver]
        );
    }

    public function errorCode(): string
    {
        return 'unsupported_driver';
    }
}
