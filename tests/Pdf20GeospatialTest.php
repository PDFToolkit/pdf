<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Geospatial as G;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Graphics\CoordinateSystems\Point;
use PDF\Serialization\FileWriter;
use PDF\Serialization\IncrementalWriter;
use PDF\Serialization\LinearizedWriter;
use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Pdf20GeospatialTest extends TestCase
{
    /** @return iterable<string,array{CrossReferenceFormat,bool}> */
    public static function layouts(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            foreach ([false, true] as $linearized) {
                yield $format->name . ($linearized ? ' linearized' : '') => [$format, $linearized];
            }
        }
    }

    #[DataProvider('layouts')]
    public function testNativeGeoPointsAndCloudSurviveWritingAndFrozenIncrementalValidation(CrossReferenceFormat $format, bool $linearized): void
    {
        $measure = $this->measure();
        $data = new G\PointData(new G\PointDataNames(new NameType('LAT'), new NameType('LON'), new NameType('ALT')), new ArrayType(new NumericType(48.85), new NumericType(2.35), new NumericType(35)));
        $viewport = new G\Viewport($this->rectangle(), $measure, new TextString('Paris'), new G\PointDataCollection($data));
        $page = new PageObject($this->rectangle(), new ResourceDictionary(), entries: $this->entry('VP', new G\Viewports($viewport)));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $file = new FileStructure(new FileHeader('2.0'), new FileBody($catalog), crossReferenceFormat: $format);
        $revision = ($linearized ? new LinearizedWriter() : new FileWriter())->revision($file);
        $record = $revision->binding($catalog);
        $updated = $record->dictionary->with($this->entry('Lang', new TextString('en')));
        $next = (new IncrementalWriter())->serialize(new IncrementalUpdate($revision, new FileBody(new IndirectObject($updated, $record->identifier))));
        self::assertStringStartsWith($revision->bytes, $next->bytes);
        self::assertStringContainsString('/Subtype /GEO', $revision->bytes);
        self::assertSame([0, 0, 1, 0], array_map(static fn (NumericType $value): int|float => $value->value, $measure->get('LPTS')->items));
    }

    public function testViewportSelectionUsesDrawingOrderAndPreservesItsAxisOrientation(): void
    {
        $first = new G\Viewport($this->rectangle());
        $last = new G\Viewport(new Rectangle(new NumericType(150), new NumericType(150), new NumericType(50), new NumericType(50)));
        $viewports = new G\Viewports($first, $last);
        self::assertSame($last, $viewports->forPoint(new Point(new NumericType(75), new NumericType(125))));
        $local = $last->local(new Point(new NumericType(75), new NumericType(125)));
        self::assertSame(0.75, $local->x->value);
        self::assertSame(0.25, $local->y->value);
        self::assertNull($viewports->forPoint(new Point(new NumericType(300), new NumericType(300))));
    }

    public function testProjectedMatrixUsesRowVectorsAndTheImplicitFourthColumn(): void
    {
        $matrix = new G\ProjectedCoordinateSystemMatrix(...array_map(static fn (int $value): NumericType => new IntegerType($value), [2,3,4,5,6,7,8,9,10,11,12,13]));
        $point = $matrix->transform(new G\LocalPosition(new NumericType(2), new NumericType(3), new NumericType(4)));
        self::assertSame(62, $point->easting->value);
        self::assertSame(72, $point->northing->value);
        self::assertSame(82, $point->elevation->value);
        self::assertNull($matrix->transform(new G\LocalPosition(new NumericType(2), new NumericType(3)))->elevation);
    }

    public function testProjectedSystemCanRetainBothReferenceFormsWhileGeographicCannot(): void
    {
        $wkt = new AsciiString('PROJCS["explicit external definition"]');
        self::assertSame($wkt, (new G\ProjectedCoordinateSystem(new G\EpsgCode(3857), $wkt))->wkt);
        $this->expectException(\InvalidArgumentException::class);
        new G\GeographicCoordinateSystem(new G\EpsgCode(4326), new AsciiString('GEOGCS["definition"]'));
    }

    /** @return iterable<string,array{string}> */
    public static function attacks(): iterable
    {
        foreach (['missing-lpts', 'wrong-pair-count', 'local-outside', 'invalid-latitude', 'missing-gcs', 'two-gcs-identifiers', 'non-ascii-wkt', 'pcsm-on-geographic', 'wrong-pdu-order', 'odd-bounds', 'outside-bounds', 'wrong-cloud-width', 'nonnumeric-latitude', 'wrong-viewport-bbox'] as $attack) {
            yield $attack => [$attack];
        }
    }

    #[DataProvider('attacks')]
    public function testRawDictionariesCannotBypassGeospatialCrossEntryRules(string $attack): void
    {
        $measure = new DictionaryType(...iterator_to_array($this->measure()->entries(), false));
        $viewport = new DictionaryType($this->entry('BBox', $this->rectangle()), $this->entry('Measure', $measure));
        switch ($attack) {
            case 'missing-lpts': $measure = $measure->without('LPTS');
                break;
            case 'wrong-pair-count': $measure = $measure->with($this->entry('LPTS', new ArrayType(new NumericType(0), new NumericType(0))));
                break;
            case 'local-outside': $measure = $measure->with($this->entry('LPTS', new ArrayType(new NumericType(0), new NumericType(0), new NumericType(2), new NumericType(0))));
                break;
            case 'invalid-latitude': $measure = $measure->with($this->entry('GPTS', new ArrayType(new NumericType(91), new NumericType(2), new NumericType(48), new NumericType(3))));
                break;
            case 'missing-gcs': $measure = $measure->without('GCS');
                break;
            case 'two-gcs-identifiers': $measure = $measure->with($this->entry('GCS', new DictionaryType($this->entry('Type', new NameType('GEOGCS')), $this->entry('EPSG', new IntegerType(4326)), $this->entry('WKT', new LiteralString('definition')))));
                break;
            case 'non-ascii-wkt': $measure = $measure->with($this->entry('DCS', new DictionaryType($this->entry('Type', new NameType('PROJCS')), $this->entry('WKT', new LiteralString("\xff")))));
                break;
            case 'pcsm-on-geographic': $measure = $measure->with($this->entry('PCSM', new ArrayType(...array_fill(0, 12, new NumericType(1)))));
                break;
            case 'wrong-pdu-order': $measure = $measure->with($this->entry('PDU', new ArrayType(new NameType('SQM'), new NameType('M'), new NameType('DEG'))));
                break;
            case 'odd-bounds': $measure = $measure->with($this->entry('Bounds', new ArrayType(...array_fill(0, 7, new NumericType(0)))));
                break;
            case 'outside-bounds': $measure = $measure->with($this->entry('Bounds', new ArrayType(...array_fill(0, 6, new NumericType(-1)))));
                break;
            case 'wrong-cloud-width': $viewport = $viewport->with($this->entry('PtData', new DictionaryType($this->entry('Type', new NameType('PtData')), $this->entry('Subtype', new NameType('Cloud')), $this->entry('Names', new ArrayType(new NameType('LAT'), new NameType('LON'))), $this->entry('XPTS', new ArrayType(new ArrayType(new NumericType(1)))))));
                break;
            case 'nonnumeric-latitude': $viewport = $viewport->with($this->entry('PtData', new DictionaryType($this->entry('Type', new NameType('PtData')), $this->entry('Subtype', new NameType('Cloud')), $this->entry('Names', new ArrayType(new NameType('LAT'))), $this->entry('XPTS', new ArrayType(new ArrayType(new LiteralString('48')))))));
                break;
            case 'wrong-viewport-bbox': $viewport = $viewport->with($this->entry('BBox', new ArrayType(new NumericType(1))));
                break;
        }
        $viewport = $viewport->with($this->entry('Measure', $measure));
        $this->expectException(\InvalidArgumentException::class);
        $this->document($viewport)->toRevision();
    }

    public function testFrozenInheritedGeoMeasureCannotBeMutatedThroughItsConstructionAlias(): void
    {
        $measure = new class (...iterator_to_array($this->measure()->entries(), false)) extends DictionaryType {
            public bool $invalid = false;
            public function entries(): iterable
            {
                foreach (parent::entries() as $entry) {
                    if (!$this->invalid || $entry->key->value !== 'LPTS') {
                        yield $entry;
                    }
                }
            }
        };
        $viewport = new DictionaryType($this->entry('BBox', $this->rectangle()), $this->entry('Measure', new IndirectReference($measure)));
        $revision = $this->document($viewport)->toRevision();
        $measure->invalid = true;
        $following = (new IncrementalUpdate($revision, new FileBody()))->toRevision();
        self::assertStringStartsWith($revision->bytes, $following->bytes);
    }

    /** @return iterable<string,array{string,int}> */
    public static function annotationContexts(): iterable
    {
        yield '3D triples' => ['3D', 3];
        yield 'RichMedia triples, EC3 284' => ['RichMedia', 3];
    }

    #[DataProvider('annotationContexts')]
    public function testAnnotationGeoUsesWorldCoordinateTriples(string $subtype, int $dimension): void
    {
        $annotation = new DictionaryType($this->entry('Type', new NameType('Annot')), $this->entry('Subtype', new NameType($subtype)), $this->entry('Rect', $this->rectangle()), $this->entry('GEO', $this->measure(dimension: $dimension)));
        $page = new PageObject($this->rectangle(), new ResourceDictionary(), entries: $this->entry('Annots', new ArrayType(new IndirectReference($annotation))));
        self::assertStringContainsString('/GEO', (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog((new PageTreeNode())->addKid($page)))))->toPdf(new \PDF\Serialization\SerializationContext()));
    }

    public function testTwoDimensionalOwnerRejectsTriplesAndOldPdfVersionRejectsRawGeo(): void
    {
        $viewport = new DictionaryType($this->entry('BBox', $this->rectangle()), $this->entry('Measure', $this->measure(dimension: 3)));
        $this->expectException(\InvalidArgumentException::class);
        $this->document($viewport)->toRevision();
    }

    public function testPdf17CannotUseAPlainRawGeoMeasure(): void
    {
        $raw = new DictionaryType(...iterator_to_array($this->measure()->entries(), false));
        $viewport = new DictionaryType($this->entry('BBox', $this->rectangle()), $this->entry('Measure', $raw));
        $this->expectException(\LogicException::class);
        $this->document($viewport, '1.7')->toRevision();
    }

    public function testAnIndirectCoordinateSystemOverrideUsesItsCurrentFrozenDictionary(): void
    {
        $system = new G\GeographicCoordinateSystem(new G\EpsgCode(4326));
        $measure = new DictionaryType(...iterator_to_array($this->measure()->entries(), false));
        $measure = $measure->with($this->entry('GCS', new IndirectReference($system)));
        $revision = $this->document(new DictionaryType($this->entry('BBox', $this->rectangle()), $this->entry('Measure', $measure)))->toRevision();
        $record = $revision->binding($system);
        $invalid = $record->dictionary->with($this->entry('WKT', new AsciiString('conflicting representation')));
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($invalid, $record->identifier))))->toRevision();
    }

    public function testCoordinateSystemCannotBeSmuggledThroughAStreamDictionary(): void
    {
        $system = new StreamObject('', new DictionaryType($this->entry('Type', new NameType('GEOGCS')), $this->entry('EPSG', new IntegerType(4326))));
        $measure = new DictionaryType(...iterator_to_array($this->measure()->entries(), false));
        $measure = $measure->with($this->entry('GCS', new IndirectReference($system)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not streams');
        $this->document(new DictionaryType($this->entry('BBox', $this->rectangle()), $this->entry('Measure', $measure)))->toRevision();
    }

    private function measure(bool $projected = false, int $dimension = 2): G\GeospatialMeasure
    {
        $first = $projected ? new G\ProjectedPosition(new NumericType(1000), new NumericType(2000), $dimension === 3 ? new NumericType(5) : null) : new G\GeographicPosition(new NumericType(48), new NumericType(2), $dimension === 3 ? new NumericType(5) : null);
        $second = $projected ? new G\ProjectedPosition(new NumericType(1100), new NumericType(2000), $dimension === 3 ? new NumericType(10) : null) : new G\GeographicPosition(new NumericType(48), new NumericType(3), $dimension === 3 ? new NumericType(10) : null);
        $points = new G\GeospatialControlPoints(new G\GeospatialControlPoint(new G\LocalPosition(new NumericType(0), new NumericType(0), $dimension === 3 ? new NumericType(20) : null), $first), new G\GeospatialControlPoint(new G\LocalPosition(new NumericType(1), new NumericType(0), $dimension === 3 ? new NumericType(30) : null), $second));
        return new G\GeospatialMeasure($projected ? new G\ProjectedCoordinateSystem(new G\EpsgCode(3857)) : new G\GeographicCoordinateSystem(new G\EpsgCode(4326)), $points);
    }

    private function document(DictionaryType $viewport, string $version = '2.0'): FileStructure
    {
        $page = new PageObject($this->rectangle(), new ResourceDictionary(), entries: $this->entry('VP', new ArrayType($viewport)));
        return new FileStructure(new FileHeader($version), new FileBody(new DocumentCatalog((new PageTreeNode())->addKid($page))));
    }

    private function rectangle(): Rectangle
    {
        return new Rectangle(new NumericType(0), new NumericType(0), new NumericType(200), new NumericType(200));
    }

    private function entry(string $key, \PDF\Syntax\FileStructure\BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
}
