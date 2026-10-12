<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\DictionaryObject;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\NameType;

class Entry
{
    public function __construct(public readonly NameType $key, public readonly BasicType $value)
    {
    }
    public function key(): NameType
    {
        return $this->key;
    }
    public function value(): BasicType
    {
        return $this->value;
    }
}
