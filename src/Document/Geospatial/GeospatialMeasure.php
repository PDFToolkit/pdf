<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Tables 266/269, physical pages 612/617-618; selected EC3 states remain explicit. */
final class GeospatialMeasure extends GeospatialDictionary
{
    public const array ERRATA = [358 => 'Completed', 533 => 'Accepted', 534 => 'Accepted', 284 => 'Accepted'];

    public function __construct(public readonly CoordinateSystem $coordinateSystem, public readonly GeospatialControlPoints $controlPoints, ?GeospatialBounds $bounds = null, ?CoordinateSystem $displayCoordinateSystem = null, ?PreferredDisplayUnits $displayUnits = null, ?ProjectedCoordinateSystemMatrix $matrix = null)
    {
        if (($coordinateSystem instanceof GeographicCoordinateSystem) !== $controlPoints->geographic) {
            throw new \InvalidArgumentException('GPTS positions use the geographic or projected kind of GCS.');
        }
        if ($matrix !== null && !$coordinateSystem instanceof ProjectedCoordinateSystem) {
            throw new \InvalidArgumentException('PCSM applies only to a projected coordinate system (EC3 #534 Accepted).');
        }
        $entries = [new Entry(new NameType('Type'), new NameType('Measure')), new Entry(new NameType('Subtype'), new NameType('GEO')), new Entry(new NameType('GCS'), $coordinateSystem), new Entry(new NameType('GPTS'), $controlPoints->world()), new Entry(new NameType('LPTS'), $controlPoints->local())];
        foreach (['Bounds' => $bounds, 'DCS' => $displayCoordinateSystem, 'PDU' => $displayUnits, 'PCSM' => $matrix] as $key => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($key), $value);
            }
        }
        parent::__construct(...$entries);
    }
}
