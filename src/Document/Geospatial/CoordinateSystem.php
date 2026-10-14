<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** The PDF reference is retained verbatim; interpreting ISO 19162 WKT is external. */
abstract class CoordinateSystem extends GeospatialDictionary
{
    protected function __construct(string $type, public readonly ?EpsgCode $epsg, public readonly ?AsciiString $wkt)
    {
        if (($epsg === null && $wkt === null) || ($wkt !== null && $wkt->value === '')) {
            throw new \InvalidArgumentException('A coordinate system supplies an EPSG reference or nonempty ASCII WKT.');
        }
        $entries = [new Entry(new NameType('Type'), new NameType($type))];
        if ($epsg !== null) {
            $entries[] = new Entry(new NameType('EPSG'), $epsg);
        }
        if ($wkt !== null) {
            $entries[] = new Entry(new NameType('WKT'), $wkt);
        }
        parent::__construct(...$entries);
    }
}
