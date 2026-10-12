<?php

declare(strict_types=1);

namespace PDF\Document\Destinations;

use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

final class FitB extends Destination
{
    public function __construct(PageObject|IndirectReference $page)
    {
        parent::__construct($page, new NameType('FitB'));
    }
}
