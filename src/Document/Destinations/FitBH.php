<?php

declare(strict_types=1);

namespace PDF\Document\Destinations;

use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

final class FitBH extends Destination
{
    public function __construct(PageObject|IndirectReference $page, ?NumericType $top = null)
    {
        parent::__construct($page, new NameType('FitBH'), $top ?? new NullType());
    }
}
