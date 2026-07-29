<?php

namespace LedgerKit\Exceptions;

class UnbalancedEntryException extends LedgerException
{
    public static function tooFewLines(int $lineCount): self
    {
        return new self(
            'A ledger entry needs at least two postings; double entry has no single sided move.',
            ['line_count' => $lineCount]
        );
    }

    public static function currency(string $currency, string $sum): self
    {
        return new self(
            "The entry does not balance in {$currency}: its postings sum to {$sum} instead of 0.",
            ['currency' => $currency, 'sum' => $sum]
        );
    }

    public function errorCode(): string
    {
        return 'unbalanced_entry';
    }
}
