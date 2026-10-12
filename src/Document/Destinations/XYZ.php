<?php

declare(strict_types=1);

namespace PDF\Document\Destinations;

use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

final class XYZ extends Destination
{
    public function __construct(PageObject|IndirectReference $page, ?NumericType $left = null, ?NumericType $top = null, ?NumericType $zoom = null)
    {
        if ($zoom !== null && $zoom->value < 0) {
            throw new \InvalidArgumentException('Destination zoom must be non-negative.');
        }
        parent::__construct($page, new NameType('XYZ'), $left ?? new NullType(), $top ?? new NullType(), $zoom ?? new NullType());
    }
}
