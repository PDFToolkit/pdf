<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\NumericType;

/** Table 269 GPTS: easting, northing and optional elevation in the declared system. */
final readonly class ProjectedPosition
{
    public function __construct(public NumericType $easting, public NumericType $northing, public ?NumericType $elevation = null)
    {
    }

    /** @return list<NumericType> */
    public function coordinates(): array
    {
        return $this->elevation === null ? [$this->easting, $this->northing] : [$this->easting, $this->northing, $this->elevation];
    }
}
