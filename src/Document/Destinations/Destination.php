<?php

declare(strict_types=1);

namespace PDF\Document\Destinations;

use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** An explicit destination (ISO 32000-1, table 151). */
abstract class Destination extends ArrayType
{
    protected function __construct(PageObject|IndirectReference $page, NameType $fit, BasicType ...$coordinates)
    {
        if ($page instanceof IndirectReference && !$page->target() instanceof PageObject) {
            throw new \InvalidArgumentException('An explicit destination must reference a page object.');
        }
        parent::__construct($page instanceof PageObject ? new IndirectReference($page) : $page, $fit, ...$coordinates);
    }
}
