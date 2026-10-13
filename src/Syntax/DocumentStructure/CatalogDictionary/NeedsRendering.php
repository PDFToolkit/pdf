<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class NeedsRendering extends Entry
{
    public function __construct(\PDF\Syntax\Object\BooleanType $value)
    {
        parent::__construct(new NameType('NeedsRendering'), $value);
    }
}
