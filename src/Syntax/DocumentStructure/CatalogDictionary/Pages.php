<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

final class Pages extends Entry
{
    public function __construct(PageTreeNode|IndirectReference $pages)
    {
        $target = $pages instanceof IndirectReference ? $pages->target() : $pages;
        if (!$target instanceof PageTreeNode || $target->parent() !== null) {
            throw new \InvalidArgumentException('Catalog Pages must reference a root page-tree node.');
        }
        parent::__construct(new NameType('Pages'), $pages instanceof IndirectReference ? $pages : new IndirectReference($pages));
    }
}
