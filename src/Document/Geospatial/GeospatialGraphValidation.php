<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\StreamObject;

/** Context, tuple and cross-entry rules for Tables 265/266/269-272, including frozen raw dictionaries. */
final readonly class GeospatialGraphValidation
{
    public function validate(BasicType $object, DictionaryType $dictionary, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $type = $this->value($dictionary, 'Type', $graph);
        $subtype = $this->value($dictionary, 'Subtype', $graph);
        if ($type instanceof NameType && $type->value === 'Page') {
            $viewports = $this->value($dictionary, 'VP', $graph);
            if ($viewports !== null) {
                $version->require(PdfVersion::PDF16, 'Page viewports');
                if (!$viewports instanceof ArrayType) {
                    throw new \InvalidArgumentException('Page.VP is an array of viewport dictionaries in drawing order.');
                }
                foreach ($viewports->items as $viewport) {
                    [$child, $scope] = $this->dictionary($viewport, $graph);
                    $this->viewport($child, $scope, $version);
                }
            }
        }
        if ($type instanceof NameType && $type->value === 'Viewport') {
            $this->viewport($dictionary, $graph, $version);
        }
        if ($object instanceof StreamObject && $subtype instanceof NameType && in_array($subtype->value, ['Form', 'Image'], true)) {
            $this->owner($dictionary, $graph, $version);
        }
        if ($type instanceof NameType && $type->value === 'Annot') {
            $this->validateAnnotation($dictionary, $graph, $version);
        }
        if ($type instanceof NameType && in_array($type->value, ['GEOGCS', 'PROJCS'], true)) {
            $version->require(PdfVersion::PDF20, 'Geospatial coordinate systems');
            $this->coordinateSystem($dictionary, $graph);
        }
        if ($type instanceof NameType && $type->value === 'PtData') {
            $version->require(PdfVersion::PDF20, 'Geospatial point data');
            $this->pointData($dictionary, $graph);
        }
    }

    /** Type is optional for annotations identified by their actual Page.Annots relationship. */
    public function validateAnnotation(DictionaryType $dictionary, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $subtype = $this->value($dictionary, 'Subtype', $graph);
        $geo = $graph->entry($dictionary, 'GEO');
        if ($geo === null || !$subtype instanceof NameType || !in_array($subtype->value, ['3D', 'RichMedia'], true)) {
            return;
        }
        $version->require(PdfVersion::PDF20, 'Geospatial3D annotation geometry');
        [$measure, $scope] = $this->dictionary($geo, $graph);
        $this->measure($measure, $scope, $version, 3);
    }

    private function viewport(DictionaryType $dictionary, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF16, 'Measurement viewports');
        $type = $this->value($dictionary, 'Type', $graph);
        if ($type !== null && (!$type instanceof NameType || $type->value !== 'Viewport')) {
            throw new \InvalidArgumentException('A viewport has Type Viewport when present.');
        }
        $bounds = $this->numbers($this->value($dictionary, 'BBox', $graph), $graph);
        if (count($bounds) !== 4 || $bounds[0]->value == $bounds[2]->value || $bounds[1]->value == $bounds[3]->value) {
            throw new \InvalidArgumentException('A viewport BBox has four coordinates and nonzero extent.');
        }
        $name = $this->value($dictionary, 'Name', $graph);
        if ($name !== null && !$name instanceof StringType) {
            throw new \InvalidArgumentException('A viewport name is a text string.');
        }
        $this->owner($dictionary, $graph, $version);
    }

    private function owner(DictionaryType $owner, DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        $entry = $graph->entry($owner, 'Measure');
        $geospatial = false;
        if ($entry !== null) {
            [$measure, $scope] = $this->dictionary($entry, $graph);
            $subtype = $this->value($measure, 'Subtype', $scope);
            $geospatial = $subtype instanceof NameType && $subtype->value === 'GEO';
            if ($geospatial) {
                $this->measure($measure, $scope, $version, 2);
            }
        }
        $pointData = $graph->entry($owner, 'PtData');
        if ($pointData !== null) {
            $version->require(PdfVersion::PDF20, 'Geospatial point data');
            if (!$geospatial) {
                throw new \InvalidArgumentException('PtData belongs to a viewport, image or form with a GEO measure.');
            }
            $data = $graph->resolve($pointData);
            foreach ($data instanceof ArrayType ? $data->items : [$pointData] as $point) {
                [$dictionary, $scope] = $this->dictionary($point, $graph);
                $this->pointData($dictionary, $scope);
            }
        }
    }

    private function measure(DictionaryType $measure, DocumentGraphContext $graph, EffectivePdfVersion $version, int $dimension): void
    {
        $version->require(PdfVersion::PDF20, 'Geospatial measures');
        $subtype = $this->value($measure, 'Subtype', $graph);
        $type = $this->value($measure, 'Type', $graph);
        if (!$subtype instanceof NameType || $subtype->value !== 'GEO' || ($type !== null && (!$type instanceof NameType || $type->value !== 'Measure'))) {
            throw new \InvalidArgumentException('A geospatial owner uses a GEO measure dictionary.');
        }
        [$system, $scope] = $this->dictionary($graph->entry($measure, 'GCS'), $graph);
        $geographic = $this->coordinateSystem($system, $scope);
        $display = $graph->entry($measure, 'DCS');
        if ($display !== null) {
            [$system, $scope] = $this->dictionary($display, $graph);
            $this->coordinateSystem($system, $scope);
        }
        $world = $this->numbers($this->value($measure, 'GPTS', $graph), $graph);
        $local = $this->numbers($this->value($measure, 'LPTS', $graph), $graph);
        if ($world === [] || count($world) !== count($local) || count($world) % $dimension !== 0) {
            throw new \InvalidArgumentException('GPTS and required LPTS have matching nonempty pairs or contextual triples (EC3 #533 Accepted).');
        }
        foreach ($world as $index => $coordinate) {
            if ($geographic && $index % $dimension === 0 && abs($coordinate->value) > 90) {
                throw new \InvalidArgumentException('Geographic GPTS latitude is in degrees between minus 90 and 90.');
            }
            if ($dimension === 2 && ($local[$index]->value < 0 || $local[$index]->value > 1)) {
                throw new \InvalidArgumentException('Two-dimensional LPTS is in the unit square; annotation world coordinates use triples.');
            }
        }
        $bounds = $this->value($measure, 'Bounds', $graph);
        if ($bounds !== null) {
            $polygon = $this->numbers($bounds, $graph);
            if (count($polygon) < 6 || count($polygon) % 2 !== 0) {
                throw new \InvalidArgumentException('A geospatial neatline contains at least three numeric pairs.');
            }
            foreach ($polygon as $number) {
                if ($number->value < 0 || $number->value > 1) {
                    throw new \InvalidArgumentException('Geospatial neatline coordinates are in the unit square.');
                }
            }
        }
        $matrix = $this->value($measure, 'PCSM', $graph);
        if ($matrix !== null && ($geographic || count($this->numbers($matrix, $graph)) !== 12)) {
            throw new \InvalidArgumentException('PCSM contains twelve numbers and applies only to PROJCS (EC3 #358 Completed, #534 Accepted).');
        }
        $units = $this->value($measure, 'PDU', $graph);
        if ($units !== null) {
            if (!$units instanceof ArrayType || count($units) !== 3) {
                throw new \InvalidArgumentException('PDU specifies linear, area and angular units in exactly three names.');
            }
            foreach ([LinearDisplayUnit::class, AreaDisplayUnit::class, AngularDisplayUnit::class] as $index => $enum) {
                $unit = $graph->resolve($units->items[$index]);
                if (!$unit instanceof NameType || $enum::tryFrom($unit->value) === null) {
                    throw new \InvalidArgumentException('PDU units are standard names in linear, area, angular order.');
                }
            }
        }
    }

    /** @return bool Whether this is a geographic rather than projected system. */
    private function coordinateSystem(DictionaryType $system, DocumentGraphContext $graph): bool
    {
        $type = $this->value($system, 'Type', $graph);
        if (!$type instanceof NameType || !in_array($type->value, ['GEOGCS', 'PROJCS'], true)) {
            throw new \InvalidArgumentException('GCS and DCS identify GEOGCS or PROJCS dictionaries.');
        }
        $epsg = $this->value($system, 'EPSG', $graph);
        $wkt = $this->value($system, 'WKT', $graph);
        if (($epsg === null && $wkt === null) || ($epsg !== null && (!$epsg instanceof IntegerType || $epsg->integer() < 1))
            || ($wkt !== null && (!$wkt instanceof StringType || $wkt->value === '' || preg_match('/\A[\x00-\x7f]+\z/', $wkt->value) !== 1))
            || ($type->value === 'GEOGCS' && $epsg !== null && $wkt !== null)) {
            throw new \InvalidArgumentException('Coordinate systems have an EPSG code or ASCII WKT; GEOGCS requires exactly one.');
        }
        return $type->value === 'GEOGCS';
    }

    private function pointData(DictionaryType $data, DocumentGraphContext $graph): void
    {
        $type = $this->value($data, 'Type', $graph);
        $subtype = $this->value($data, 'Subtype', $graph);
        $names = $this->value($data, 'Names', $graph);
        $points = $this->value($data, 'XPTS', $graph);
        if (!$type instanceof NameType || $type->value !== 'PtData' || !$subtype instanceof NameType || $subtype->value !== 'Cloud' || !$names instanceof ArrayType || count($names) === 0 || !$points instanceof ArrayType) {
            throw new \InvalidArgumentException('Point data has Type PtData, Subtype Cloud, Names and XPTS arrays.');
        }
        $columns = [];
        foreach ($names->items as $name) {
            $name = $graph->resolve($name);
            if (!$name instanceof NameType || $name->value === '' || isset($columns[$name->value])) {
                throw new \InvalidArgumentException('Point-data columns are distinct nonempty names.');
            }
            $columns[$name->value] = true;
        }
        foreach ($points->items as $tuple) {
            $tuple = $graph->resolve($tuple);
            if (!$tuple instanceof ArrayType || count($tuple) !== count($names)) {
                throw new \InvalidArgumentException('Each XPTS tuple has the Names column count.');
            }
            foreach (array_keys($columns) as $index => $name) {
                $value = $graph->resolve($tuple->items[$index]);
                if (in_array($name, ['LAT', 'LON', 'ALT'], true) && (!$value instanceof NumericType || ($name === 'LAT' && abs($value->value) > 90))) {
                    throw new \InvalidArgumentException('Predefined point-data columns have numeric geographic values.');
                }
            }
        }
    }

    /** @return list<NumericType> */
    private function numbers(?BasicType $value, DocumentGraphContext $graph): array
    {
        if (!$value instanceof ArrayType) {
            throw new \InvalidArgumentException('Geospatial coordinates form a numeric array.');
        }
        $numbers = [];
        foreach ($value->items as $number) {
            $number = $graph->resolve($number);
            if (!$number instanceof NumericType) {
                throw new \InvalidArgumentException('Geospatial coordinate array members are PDF numbers.');
            }
            $numbers[] = $number;
        }
        return $numbers;
    }

    private function value(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): ?BasicType
    {
        $value = $graph->entry($dictionary, $key);
        return $value === null ? null : $graph->resolve($value);
    }

    /** @return array{DictionaryType,DocumentGraphContext} */
    private function dictionary(?BasicType $value, DocumentGraphContext $graph): array
    {
        if ($value === null) {
            throw new \InvalidArgumentException('A required geospatial dictionary is missing.');
        }
        $scope = $value instanceof IndirectReference ? $graph : $graph->forDirectValue($value);
        if (!$scope->resolve($value) instanceof DictionaryType) {
            throw new \InvalidArgumentException('Geospatial dictionaries are dictionary objects, not streams.');
        }
        return [$scope->dictionary($value) ?? throw new \InvalidArgumentException('A geospatial relationship identifies a dictionary.'), $scope];
    }
}
