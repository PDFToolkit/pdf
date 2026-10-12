<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\NameType;

final class Lang extends Entry
{
    public function __construct(\PDF\Syntax\DataStructure\TextString $value)
    {
        parent::__construct(new NameType('Lang'), $value);
    }
}
