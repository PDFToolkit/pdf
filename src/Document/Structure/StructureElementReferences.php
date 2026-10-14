<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Structure-element Ref entry; ISO_32000-2_sponsored_EC3.pdf, table 355, physical page 740. */
final class StructureElementReferences extends Entry
{
    public function __construct(StructureElement ...$targets)
    {
        if (count(array_unique(array_map(spl_object_id(...), $targets))) !== count($targets)) {
            throw new \InvalidArgumentException('A Ref array requires distinct explicit structure-element targets.');
        }
        parent::__construct(new NameType('Ref'), new ArrayType(...array_map(static fn (StructureElement $target): IndirectReference => new IndirectReference($target), $targets)));
    }
}
