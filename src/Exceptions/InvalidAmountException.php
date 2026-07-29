<?php

namespace LedgerKit\Exceptions;

class InvalidAmountException extends LedgerException
{
    public static function zero(string $currency): self
    {
        return new self(
            'A posting amount must not be zero.',
            ['currency' => $currency, 'reason' => 'zero']
        );
    }

    public static function floatInput(string $currency): self
    {
        return new self(
            'A posting amount must be integer minor units or an exact decimal string; floats cannot represent money exactly.',
            ['currency' => $currency, 'reason' => 'float']
        );
    }

    public static function format(string $currency): self
    {
        return new self(
            'A posting amount string must be an optionally signed decimal number, for example "12.50".',
            ['currency' => $currency, 'reason' => 'format']
        );
    }

    public static function precision(string $currency, int $exponent): self
    {
        return new self(
            "A posting amount in {$currency} carries at most {$exponent} decimal places; finer precision cannot be stored.",
            ['currency' => $currency, 'reason' => 'precision', 'exponent' => $exponent]
        );
    }

    public static function outOfRange(string $currency): self
    {
        return new self(
            'A posting amount is outside the range a signed 64 bit minor unit column can hold.',
            ['currency' => $currency, 'reason' => 'out_of_range']
        );
    }

    public function errorCode(): string
    {
        return 'invalid_amount';
    }
}
