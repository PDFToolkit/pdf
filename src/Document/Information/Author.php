<?php

declare(strict_types=1);

namespace PDF\Document\Information;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class Author extends Entry
{
    public function __construct(TextString $value)
    {
        parent::__construct(new NameType('Author'), $value);
    }
}
