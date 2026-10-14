<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\DataStructure\AsciiString;

/** Table 270, physical page 619: EPSG and WKT are mutually exclusive. */
final class GeographicCoordinateSystem extends CoordinateSystem
{
    public function __construct(?EpsgCode $epsg = null, ?AsciiString $wkt = null)
    {
        if ($epsg !== null && $wkt !== null) {
            throw new \InvalidArgumentException('A geographic coordinate system uses exactly one of EPSG and WKT.');
        }
        parent::__construct('GEOGCS', $epsg, $wkt);
    }
}
