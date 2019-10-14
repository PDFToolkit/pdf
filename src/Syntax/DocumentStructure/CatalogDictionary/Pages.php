<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;

class Pages implements Entry
{
    public function __invoke()
    {
        return new PageTreeNode();
    }
}
