<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\PageDictionary;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

final class Rotate extends Entry
{
    public function __construct(IntegerType $angle)
    {
        if ($angle->value % 90 !== 0) {
            throw new \InvalidArgumentException('Page rotation must be a multiple of 90 degrees.');
        }
        parent::__construct(new NameType('Rotate'), $angle);
    }
}
