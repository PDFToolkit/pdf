<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class Legal extends Entry
{
    public function __construct(DictionaryType|IndirectReference $value)
    {
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        if (!$target instanceof DictionaryType) {
            throw new \InvalidArgumentException('Invalid value for catalog Legal.');
        }
        parent::__construct(new NameType('Legal'), $value);
    }
}
