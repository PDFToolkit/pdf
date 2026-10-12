<?php

declare(strict_types=1);

namespace PDF\Document\Destinations;

use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

final class FitR extends Destination
{
    public function __construct(PageObject|IndirectReference $page, NumericType $left, NumericType $bottom, NumericType $right, NumericType $top)
    {
        parent::__construct($page, new NameType('FitR'), $left, $bottom, $right, $top);
    }
}
