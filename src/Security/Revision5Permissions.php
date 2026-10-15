<?php

declare(strict_types=1);

namespace PDF\Security;

/** Historical R5 P flags, ISO1 Table22; TS32004 Table3 (retained PDF p11) assigns bit13 to MAC policy. */
final readonly class Revision5Permissions
{
    public function __construct(public int $value, public bool $requireMac = false)
    {
        $reserved = $requireMac ? -8000 : -3904;
        if ($value < -2147483648 || $value > -1 || ($value & 3) !== 0 || ($value & $reserved) !== $reserved
            || (($value & 0x1000) === 0) !== $requireMac) {
            throw new \InvalidArgumentException('Historical R5 permissions require signed 32-bit P flags, prescribed reserved bits and a consistent MAC policy.');
        }
    }

    /** Algorithm 3.10, supplement PDF page 21, bytes 0–7 in little-endian order. */
    public function bytes(): string
    {
        return pack('V', $this->value) . "\xff\xff\xff\xff";
    }
}
