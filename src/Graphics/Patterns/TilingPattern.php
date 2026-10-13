<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns;

use PDF\Document\ResourceDictionary;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Specification\Dictionary\Type1PatternDictionaryTable75;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/** Type 1 cell stream with explicit spacing, resources, painting mode and matrix (pages 182–187). */
final class TilingPattern extends StreamObject implements \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF12, 'Tiling pattern');
    }

    public function __construct(ContentStreams $cell, public readonly PaintType $paintType, TilingType $tilingType, Rectangle $boundingBox, NumericType $xStep, NumericType $yStep, ResourceDictionary $resources, Matrix $matrix = new Matrix(), Filter ...$filters)
    {
        if ($xStep->value == 0 || $yStep->value == 0) {
            throw new \InvalidArgumentException('Pattern XStep and YStep may be positive or negative, but cannot be zero.');
        }
        $cell->validate();
        if ($paintType === PaintType::Uncoloured) {
            (new UncolouredPatternValidator())->validate($cell, $resources);
        }
        parent::__construct($cell, new Type1PatternDictionaryTable75(
            new Entry(new NameType('Type'), new NameType('Pattern')),
            new Entry(new NameType('PatternType'), new IntegerType(1)),
            new Entry(new NameType('PaintType'), new IntegerType($paintType->value)),
            new Entry(new NameType('TilingType'), new IntegerType($tilingType->value)),
            new Entry(new NameType('BBox'), $boundingBox),
            new Entry(new NameType('XStep'), $xStep),
            new Entry(new NameType('YStep'), $yStep),
            new Entry(new NameType('Resources'), $resources),
            new Entry(new NameType('Matrix'), new ArrayType(...$matrix->operands())),
        ), ...$filters);
    }
}
