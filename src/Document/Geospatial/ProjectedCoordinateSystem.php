<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\DataStructure\AsciiString;

/** Table 271, physical page 620: at least one identifier is required; both may occur. */
final class ProjectedCoordinateSystem extends CoordinateSystem
{
    public function __construct(?EpsgCode $epsg = null, ?AsciiString $wkt = null)
    {
        parent::__construct('PROJCS', $epsg, $wkt);
    }
}
