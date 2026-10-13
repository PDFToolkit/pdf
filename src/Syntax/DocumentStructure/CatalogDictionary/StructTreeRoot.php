<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class StructTreeRoot extends Entry
{
    public function __construct(DictionaryType|IndirectReference $value)
    {
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        if (!$target instanceof DictionaryType) {
            throw new \InvalidArgumentException('Invalid value for catalog StructTreeRoot.');
        }
        parent::__construct(new NameType('StructTreeRoot'), $value);
    }
}
