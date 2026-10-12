<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\PageDictionary;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

final class UserUnit extends Entry
{
    public function __construct(NumericType $unit)
    {
        if ($unit->value <= 0) {
            throw new \InvalidArgumentException('A page user unit must be positive.');
        }
        parent::__construct(new NameType('UserUnit'), $unit);
    }
}
