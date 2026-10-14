<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NameType;

/** Table 269: one linear, area and angular display unit, in that order. */
final class PreferredDisplayUnits extends ArrayType
{
    public function __construct(public readonly LinearDisplayUnit $linear, public readonly AreaDisplayUnit $area, public readonly AngularDisplayUnit $angular)
    {
        parent::__construct(new NameType($linear->value), new NameType($area->value), new NameType($angular->value));
    }
}
