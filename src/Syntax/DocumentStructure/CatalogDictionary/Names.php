<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class Names extends Entry
{
    public function __construct(\PDF\Syntax\DocumentStructure\NameDictionary|IndirectReference $value)
    {
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        if (!$target instanceof \PDF\Syntax\DocumentStructure\NameDictionary) {
            throw new \InvalidArgumentException('Invalid value for catalog Names.');
        }
        parent::__construct(new NameType('Names'), $value);
    }
}
