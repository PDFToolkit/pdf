<?php

declare(strict_types=1);

namespace PDF\Document\Information;

use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class ModDate extends Entry
{
    public function __construct(Date $value)
    {
        parent::__construct(new NameType('ModDate'), $value);
    }
}
