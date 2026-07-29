<?php

namespace LedgerKit\Exceptions;

class InvalidEntryException extends LedgerException
{
    public static function metadataLength(string $field, int $max): self
    {
        return new self(
            "Entry {$field} must be 1 to {$max} characters.",
            ['field' => $field, 'max' => $max]
        );
    }

    public static function unknownEntry(string $id): self
    {
        return new self(
            "No ledger entry with id [{$id}].",
            ['entry_id' => $id]
        );
    }

    public function errorCode(): string
    {
        return 'invalid_entry';
    }
}
