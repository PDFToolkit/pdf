<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure;

use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;

/** A page-tree node or leaf (ISO 32000-1, 7.7.3). */
interface PageTree extends BasicType
{
    public function parent(): ?PageTreeNode;

    /** @internal Attach through PageTreeNode::addKid(). */
    public function attachTo(PageTreeNode $parent): void;

    public function pageCount(): int;
}
