<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\StringType;

/** The existing 32-byte O entry used by historical Algorithm 3.2. */
final readonly class HistoricalOwnerPasswordEntry
{
    public function __construct(public StringType $value)
    {
        if (strlen($value->value) !== 32) {
            throw new \InvalidArgumentException('The legacy owner-password O entry must contain exactly 32 bytes.');
        }
    }
}
