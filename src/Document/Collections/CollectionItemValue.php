<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

final readonly class CollectionItemValue
{
    public function __construct(public NameType $field, public TextString|Date|NumericType|CollectionSubitem $value)
    {
        if ($field->value === 'Type') {
            throw new \InvalidArgumentException('A collection-item value cannot replace Type.');
        }
    }
}
