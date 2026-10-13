<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;

/** Select a free number for a distinct ordinary value; its generation comes from the effective free entry. */
final readonly class ReuseObjectNumber
{
    public function __construct(public ObjectNumber $number, public BasicType $value)
    {
        if ($value instanceof IndirectReference) {
            throw new \InvalidArgumentException('A reused indirect value cannot itself be an indirect reference.');
        }
    }
}
