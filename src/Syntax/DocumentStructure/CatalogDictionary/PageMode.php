<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Document\PageMode as Value;
use PDF\Syntax\Object\NameType;

final class PageMode extends Entry
{
    public function __construct(Value $value)
    {
        parent::__construct(new NameType('PageMode'), new NameType($value->value));
    }
}
