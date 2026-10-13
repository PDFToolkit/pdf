<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\IntegerType;

/** PDF key length in bits, including Adobe AESV3's 256-bit extension. */
final readonly class PublicKeyKeyLength
{
    public function __construct(public IntegerType $value)
    {
        if (($value->value < 40 || $value->value > 128 || $value->integer() % 8 !== 0) && $value->value !== 256) {
            throw new \InvalidArgumentException('Public-key key length is 40–128 bits in whole bytes, or 256 bits for AESV3.');
        }
    }

    public function bytes(): int
    {
        return intdiv($this->value->integer(), 8);
    }
}
