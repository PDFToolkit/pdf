<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Document\OutlineDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class Outlines extends Entry
{
    public function __construct(OutlineDictionary|IndirectReference $value)
    {
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        if (!$target instanceof OutlineDictionary) {
            throw new \InvalidArgumentException('Invalid value for catalog Outlines.');
        }
        parent::__construct(new NameType('Outlines'), $value);
    }
}
