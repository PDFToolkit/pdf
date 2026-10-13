<?php

declare(strict_types=1);

namespace PDF\Document\Extensions;

use PDF\Syntax\Object\NameType;

/** A case-sensitive four-byte registered developer prefix (ISO 32000-1, 7.12). */
final readonly class DeveloperPrefix
{
    public function __construct(public NameType $name)
    {
        if (strlen($name->value) !== 4 || $name->value === 'Type') {
            throw new \InvalidArgumentException('A developer prefix requires four bytes and cannot use the reserved Type key.');
        }
    }
}
