<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class PageLabels extends Entry
{
    public function __construct(\PDF\Syntax\DataStructure\NumberTree|IndirectReference $value)
    {
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        if (!$target instanceof \PDF\Syntax\DataStructure\NumberTree) {
            throw new \InvalidArgumentException('Invalid value for catalog PageLabels.');
        }
        parent::__construct(new NameType('PageLabels'), $value);
    }
}
