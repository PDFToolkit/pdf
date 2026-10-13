<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class Outlines extends Entry
{
    public function __construct(\PDF\Dictionary\OutlineDictionary|IndirectReference $value)
    {
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        if (!$target instanceof \PDF\Dictionary\OutlineDictionary) {
            throw new \InvalidArgumentException('Invalid value for catalog Outlines.');
        }
        parent::__construct(new NameType('Outlines'), $value);
    }
}
