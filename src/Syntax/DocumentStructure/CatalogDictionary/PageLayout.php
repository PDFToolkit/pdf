<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Document\PageLayout as Value;
use PDF\Syntax\Object\NameType;

final class PageLayout extends Entry
{
    public function __construct(Value $value)
    {
        parent::__construct(new NameType('PageLayout'), new NameType($value->value));
    }
}
