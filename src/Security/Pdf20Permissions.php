<?php

declare(strict_types=1);

namespace PDF\Security;

/** ISO 32000-2 Table 22; TS 32004 Table 3 assigns reserved bit 13 to MAC policy. */
final readonly class Pdf20Permissions
{
    public function __construct(public int $value, public bool $requireMac = false)
    {
        $reserved = $requireMac ? 0xffffe0c0 : 0xfffff0c0;
        if ($value < -2147483648 || $value >= 0 || ($value & 3) !== 0
            || ($value & $reserved) !== $reserved || ($value & 0x200) === 0
            || (($value & 0x1000) === 0) !== $requireMac) {
            throw new \InvalidArgumentException('PDF 2.0 permissions require accessibility, reserved bits and a consistent MAC policy.');
        }
    }

    public function bytes(): string
    {
        return pack('V', $this->value) . "\xff\xff\xff\xff";
    }
}
