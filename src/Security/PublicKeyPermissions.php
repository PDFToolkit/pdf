<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\IntegerType;

/** Public-key permission bits are an unsigned 32-bit word, PDF32000_2008.pdf, Table24, PDF pages73–74. */
final readonly class PublicKeyPermissions
{
    public int $unsigned;

    public function __construct(public IntegerType $value)
    {
        if ($value->value < -2147483648 || $value->value > 4294967295) {
            throw new \InvalidArgumentException('Public-key permissions must fit a 32-bit word.');
        }
        $this->unsigned = $value->integer() & 0xffffffff;
    }

    public function bytes(PublicKeyPermissionByteOrder $order): string
    {
        return pack($order->value, $this->unsigned);
    }

    public function ownerAccess(): bool
    {
        return ($this->unsigned & 2) !== 0;
    }
}
