<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\NameType;

final class Type extends Entry
{
    public function __construct()
    {
        parent::__construct(new NameType('Type'), new NameType('Catalog'));
    }
}
