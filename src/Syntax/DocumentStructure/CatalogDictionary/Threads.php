<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class Threads extends Entry
{
    public function __construct(IndirectReference $value)
    {
        $target = $value->target();
        if (!$target instanceof ArrayType) {
            throw new \InvalidArgumentException('Invalid value for catalog Threads.');
        }
        parent::__construct(new NameType('Threads'), $value);
    }
}
