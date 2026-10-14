<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\NumericType;

/** Table 269 GPTS: latitude first, longitude second, optional elevation third. */
final readonly class GeographicPosition
{
    public function __construct(public NumericType $latitude, public NumericType $longitude, public ?NumericType $elevation = null)
    {
        if (abs($latitude->value) > 90) {
            throw new \InvalidArgumentException('Geographic latitude in degrees lies between minus 90 and 90.');
        }
    }

    /** @return list<NumericType> */
    public function coordinates(): array
    {
        return $this->elevation === null ? [$this->latitude, $this->longitude] : [$this->latitude, $this->longitude, $this->elevation];
    }
}
