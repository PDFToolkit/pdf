<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\IntegerType;

/** ISO table 22, PDF page 69, including revision-specific reserved permission bits. */
final readonly class HistoricalPermissions
{
    public function __construct(public IntegerType $value, public HistoricalSecurityRevision $revision)
    {
        $flags = $value->integer();
        $mask = $revision === HistoricalSecurityRevision::Revision2 ? -64 : -3904;
        if ($flags < -2147483648 || $flags > -1 || ($flags & 3) !== 0 || ($flags & $mask) !== $mask) {
            throw new \InvalidArgumentException('Historical permission flags must preserve the revision-specific reserved bits.');
        }
    }
}
