<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\NumericType;

/** Table 269: unit-square coordinates in 2D, annotation world coordinates in 3D. */
final readonly class LocalPosition
{
    public function __construct(public NumericType $x, public NumericType $y, public ?NumericType $z = null)
    {
    }

    /** @return list<NumericType> */
    public function coordinates(): array
    {
        return $this->z === null ? [$this->x, $this->y] : [$this->x, $this->y, $this->z];
    }
}
