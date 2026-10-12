<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Syntax\FileStructure\BasicType;

interface OutlineParent extends BasicType
{
    /** @return list<OutlineItem> */
    public function items(): array;
}
