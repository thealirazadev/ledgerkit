<?php

namespace LedgerKit\Exceptions;

use RuntimeException;
use Throwable;

abstract class LedgerException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $context  Ids and codes only, never posting lines.
     */
    public function __construct(string $message, protected array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Stable machine readable code from docs/api-contracts.md. Never changes meaning. */
    abstract public function errorCode(): string;

    /** @return array<string, scalar|null> */
    public function context(): array
    {
        return $this->context;
    }
}
