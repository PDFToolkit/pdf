<?php

declare(strict_types=1);

namespace PDF\Security;

/** The signed P flags, with the reserved bits required by ISO 32000-1 Table 22. */
final readonly class Revision5Permissions
{
    public function __construct(public int $value)
    {
        if ($value < -2147483648 || $value > -1 || ($value & 3) !== 0 || ($value & -3904) !== -3904) {
            throw new \InvalidArgumentException('Permissions must be signed 32-bit P flags with the prescribed reserved bits.');
        }
    }

    /** Algorithm 3.10, supplement PDF page 21, bytes 0–7 in little-endian order. */
    public function bytes(): string
    {
        return pack('V', $this->value) . "\xff\xff\xff\xff";
    }
}
