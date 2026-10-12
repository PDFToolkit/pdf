<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class OutputIntents extends Entry
{
    public function __construct(ArrayType|IndirectReference $value)
    {
        $target = $value instanceof IndirectReference ? $value->target() : $value;
        if (!$target instanceof ArrayType) {
            throw new \InvalidArgumentException('Invalid value for catalog OutputIntents.');
        }
        parent::__construct(new NameType('OutputIntents'), $value);
    }
}
