<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Graphics\CoordinateSystems\Point;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Table 265 and 12.9.1, physical page 611: a PDF measurement viewport. */
final class Viewport extends GeospatialDictionary
{
    public function __construct(public readonly Rectangle $bounds, ?DictionaryType $measure = null, ?TextString $name = null, PointData|PointDataCollection|null $pointData = null)
    {
        if ($bounds->lowerLeftX->value == $bounds->upperRightX->value || $bounds->lowerLeftY->value == $bounds->upperRightY->value) {
            throw new \InvalidArgumentException('A measurement viewport has nonzero extent in both directions.');
        }
        if ($measure instanceof GeospatialMeasure && $measure->controlPoints->dimension !== 2) {
            throw new \InvalidArgumentException('Viewport geospatial correspondences use two-dimensional tuples.');
        }
        if ($pointData !== null && !$measure instanceof GeospatialMeasure) {
            throw new \InvalidArgumentException('Extended point data belongs to a GEO measurement owner.');
        }
        $entries = [new Entry(new NameType('Type'), new NameType('Viewport')), new Entry(new NameType('BBox'), $bounds)];
        foreach (['Measure' => $measure, 'Name' => $name, 'PtData' => $pointData] as $key => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($key), $value);
            }
        }
        parent::__construct(...$entries);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF16, 'Measurement viewports');
    }

    public function contains(Point $point): bool
    {
        return $point->x->value >= min($this->bounds->lowerLeftX->value, $this->bounds->upperRightX->value) && $point->x->value <= max($this->bounds->lowerLeftX->value, $this->bounds->upperRightX->value)
            && $point->y->value >= min($this->bounds->lowerLeftY->value, $this->bounds->upperRightY->value) && $point->y->value <= max($this->bounds->lowerLeftY->value, $this->bounds->upperRightY->value);
    }

    public function local(Point $point): LocalPosition
    {
        return new LocalPosition(new NumericType(($point->x->value - $this->bounds->lowerLeftX->value) / ($this->bounds->upperRightX->value - $this->bounds->lowerLeftX->value)), new NumericType(($point->y->value - $this->bounds->lowerLeftY->value) / ($this->bounds->upperRightY->value - $this->bounds->lowerLeftY->value)));
    }
}
