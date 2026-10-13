<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\ResourceDictionary;
use PDF\Functions\Calculator\Number;
use PDF\Functions\Calculator\Operator as CalculatorOperator;
use PDF\Functions\Calculator\Procedure;
use PDF\Functions\Domain;
use PDF\Functions\NumberArray;
use PDF\Functions\NumberInterval;
use PDF\Functions\PostScriptCalculatorFunction;
use PDF\Functions\Range;
use PDF\Graphics\ColourSpaces\BlackPoint;
use PDF\Graphics\ColourSpaces\CalGray;
use PDF\Graphics\ColourSpaces\CalRGB;
use PDF\Graphics\ColourSpaces\ColorantDictionary;
use PDF\Graphics\ColourSpaces\ColourComponentNames;
use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Graphics\ColourSpaces\DeviceColourSpace;
use PDF\Graphics\ColourSpaces\DeviceN;
use PDF\Graphics\ColourSpaces\DeviceNAttributes;
use PDF\Graphics\ColourSpaces\DeviceNMixingHints;
use PDF\Graphics\ColourSpaces\DeviceNProcess;
use PDF\Graphics\ColourSpaces\GammaValues;
use PDF\Graphics\ColourSpaces\ICCBased;
use PDF\Graphics\ColourSpaces\ICCProfile;
use PDF\Graphics\ColourSpaces\Indexed;
use PDF\Graphics\ColourSpaces\InkSolidities;
use PDF\Graphics\ColourSpaces\Lab;
use PDF\Graphics\ColourSpaces\MatrixABC;
use PDF\Graphics\ColourSpaces\Pattern;
use PDF\Graphics\ColourSpaces\Separation;
use PDF\Graphics\ColourSpaces\TintTransform;
use PDF\Graphics\ColourSpaces\WhitePoint;
use PDF\Graphics\Patterns\PaintType;
use PDF\Graphics\Patterns\PatternColour;
use PDF\Graphics\Patterns\ShadingPattern;
use PDF\Graphics\Patterns\Shadings\AxialShading;
use PDF\Graphics\Patterns\Shadings\Circle;
use PDF\Graphics\Patterns\Shadings\CoonsPatch;
use PDF\Graphics\Patterns\Shadings\CoonsPatchShading;
use PDF\Graphics\Patterns\Shadings\DecodeInterval;
use PDF\Graphics\Patterns\Shadings\FreeFormGouraudShading;
use PDF\Graphics\Patterns\Shadings\FreeFormMesh;
use PDF\Graphics\Patterns\Shadings\FreeFormTriangle;
use PDF\Graphics\Patterns\Shadings\FunctionBasedShading;
use PDF\Graphics\Patterns\Shadings\LatticeGouraudShading;
use PDF\Graphics\Patterns\Shadings\LatticeMesh;
use PDF\Graphics\Patterns\Shadings\MeshBitDepths;
use PDF\Graphics\Patterns\Shadings\MeshBitWriter;
use PDF\Graphics\Patterns\Shadings\MeshDecode;
use PDF\Graphics\Patterns\Shadings\MeshFormat;
use PDF\Graphics\Patterns\Shadings\MeshVertex;
use PDF\Graphics\Patterns\Shadings\PatchColours;
use PDF\Graphics\Patterns\Shadings\PatchContinuation;
use PDF\Graphics\Patterns\Shadings\PatchControlPoints;
use PDF\Graphics\Patterns\Shadings\PatchMesh;
use PDF\Graphics\Patterns\Shadings\Point;
use PDF\Graphics\Patterns\Shadings\RadialShading;
use PDF\Graphics\Patterns\Shadings\ShadingExtension;
use PDF\Graphics\Patterns\Shadings\ShadingFunctions;
use PDF\Graphics\Patterns\Shadings\ShadingOptions;
use PDF\Graphics\Patterns\Shadings\TensorProductPatch;
use PDF\Graphics\Patterns\Shadings\TensorProductPatchShading;
use PDF\Graphics\Patterns\Shadings\TriangleContinuation;
use PDF\Graphics\Patterns\TilingPattern;
use PDF\Graphics\Patterns\TilingType;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColourPatternsTest extends TestCase
{
    public function testDeviceNamesAndExplicitComponentClipping(): void
    {
        foreach ([[ColourSpaceFamily::DeviceGray, 1], [ColourSpaceFamily::DeviceRGB, 3], [ColourSpaceFamily::DeviceCMYK, 4]] as [$family, $count]) {
            $space = new DeviceColourSpace($family);
            self::assertSame($count, $space->componentCount());
            self::assertSame('/' . $family->value, $space->value()->toPdf(new SerializationContext()));
        }
        $rgb = $this->rgb();
        $original = new ColourComponents($rgb, new NumericType(-1), new NumericType(0.5), new NumericType(2));
        self::assertSame([-1, 0.5, 2], array_map(static fn (NumericType $number): int|float => $number->value, $original->numbers));
        self::assertSame([0, 0.5, 1], array_map(static fn (NumericType $number): int|float => $number->value, $original->clipped()->numbers));
    }

    public function testCalibratedGrayAndRgbExecuteSourceXyzTransformations(): void
    {
        $gray = new CalGray($this->white(), new NumericType(2));
        $xyz = $gray->toXYZ(new ColourComponents($gray, new NumericType(0.5)));
        self::assertEqualsWithDelta(0.25 * 0.9505, $xyz->x->value, 1e-10);
        self::assertSame(0.25, $xyz->y->value);
        $rgb = new CalRGB($this->white(), new GammaValues(new NumericType(2), new NumericType(1), new NumericType(1)), new MatrixABC(new NumericType(2), new NumericType(3), new NumericType(4), new NumericType(5), new NumericType(6), new NumericType(7), new NumericType(8), new NumericType(9), new NumericType(10)));
        $xyz = $rgb->toXYZ(new ColourComponents($rgb, new NumericType(0.5), new NumericType(0.2), new NumericType(0.1)));
        self::assertEqualsWithDelta(2.3, $xyz->x->value, 1e-10);
        self::assertEqualsWithDelta(2.85, $xyz->y->value, 1e-10);
        self::assertEqualsWithDelta(3.4, $xyz->z->value, 1e-10);
        self::assertStringContainsString('/WhitePoint [0.9505 1 1.089]', $gray->toPdf(new SerializationContext()));
    }

    public function testLabPiecewiseTransformationMatchesBlackAndWhite(): void
    {
        $lab = new Lab($this->white());
        $black = $lab->toXYZ(new ColourComponents($lab, new NumericType(0), new NumericType(0), new NumericType(0)));
        self::assertEqualsWithDelta(0, $black->x->value, 1e-12);
        $white = $lab->toXYZ(new ColourComponents($lab, new NumericType(100), new NumericType(0), new NumericType(0)));
        self::assertEqualsWithDelta(0.9505, $white->x->value, 1e-12);
        self::assertEqualsWithDelta(1, $white->y->value, 1e-12);
        self::assertEqualsWithDelta(1.089, $white->z->value, 1e-12);
    }

    public function testWhitePointRejectsInvalidNormalization(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WhitePoint(new NumericType(1), new NumericType(2), new NumericType(1));
    }

    public function testBlackPointRejectsNegativeCoordinates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BlackPoint(new NumericType(-1));
    }

    public function testGammaMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CalGray($this->white(), new NumericType(0));
    }

    public function testTrueIccProfileHasPdfMetadataAndPreservesPayload(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/graphics/srgb.icc');
        self::assertIsString($bytes);
        $profile = new ICCProfile($bytes, new IntegerType(3), $this->rgb(), null, null, new FlateDecode());
        self::assertSame($bytes, $profile->data);
        self::assertSame(3, $profile->dictionary()->get('N')->value);
        self::assertSame('/DeviceRGB', $profile->effectiveAlternate()->value()->toPdf(new SerializationContext()));
        self::assertSame($profile, (new ICCBased($profile))->items[1]->target());
        self::assertStringContainsString('/Filter /FlateDecode', $profile->toPdf(new SerializationContext()));
    }

    public function testIccAlternateMustHaveTheDeclaredComponentCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ICCProfile('opaque profile', new IntegerType(4), $this->rgb());
    }

    public function testIndexedLookupHasExactSizeAndScalesLabRanges(): void
    {
        $lab = new Lab($this->white());
        $indexed = new Indexed($lab, new IntegerType(1), new HexadecimalString("\x00\x00\xFF\xFF\x80\x00"));
        $colour = $indexed->colourAt(new NumericType(0));
        self::assertEquals(0, $colour->numbers[0]->value);
        self::assertEquals(-100, $colour->numbers[1]->value);
        self::assertEquals(100, $colour->numbers[2]->value);
        self::assertEquals(100, $indexed->colourAt(new NumericType(100))->numbers[0]->value);
    }

    public function testIndexedLookupRejectsTruncatedPalettes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Indexed($this->rgb(), new IntegerType(1), new HexadecimalString('short'));
    }

    public function testSeparationAndNchannelPreserveNamedColourRelationships(): void
    {
        $space = $this->rgb();
        $spot = new Separation(new NameType('Orange'), new TintTransform($this->function(1, 3), 1, $space));
        $names = new ColourComponentNames(new NameType('R'), new NameType('G'), new NameType('B'), new NameType('Orange'));
        $attributes = new DeviceNAttributes(new NameType('NChannel'), new ColorantDictionary($spot), new DeviceNProcess($space, new ColourComponentNames(new NameType('R'), new NameType('G'), new NameType('B'))));
        $deviceN = new DeviceN($names, new TintTransform($this->function(4, 3), 4, $space), $attributes);
        self::assertSame(4, $deviceN->componentCount());
        self::assertStringContainsString('/DeviceN [/R /G /B /Orange]', $deviceN->toPdf(new SerializationContext()));
        self::assertSame($spot, $attributes->colorants->get('Orange'));
    }

    public function testDeviceNAllowsRepeatedNoneButRejectsAll(): void
    {
        $space = $this->rgb();
        $none = new DeviceN(new ColourComponentNames(new NameType('None'), new NameType('None')), new TintTransform($this->function(2, 3), 2, $space));
        self::assertSame(2, $none->componentCount());
        $this->expectException(\InvalidArgumentException::class);
        new DeviceN(new ColourComponentNames(new NameType('All')), new TintTransform($this->function(1, 3), 1, $space));
    }

    public function testNchannelRequiresEverySpotDefinition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeviceN(new ColourComponentNames(new NameType('Spot')), new TintTransform($this->function(1, 3), 1, $this->rgb()), new DeviceNAttributes(new NameType('NChannel')));
    }

    public function testNchannelRejectsOutOfOrderRgbProcessComponents(): void
    {
        $rgb = $this->rgb();
        $process = new DeviceNProcess($rgb, new ColourComponentNames(new NameType('R'), new NameType('G'), new NameType('B')));
        $this->expectException(\InvalidArgumentException::class);
        new DeviceN(new ColourComponentNames(new NameType('B'), new NameType('G'), new NameType('R')), new TintTransform($this->function(3, 3), 3, $rgb), new DeviceNAttributes(new NameType('NChannel'), process: $process));
    }

    public function testMixingSoliditiesRequirePrintingOrderAndAllComponents(): void
    {
        $inks = new InkSolidities(new Entry(new NameType('Orange'), new NumericType(0.5)));
        $hints = new DeviceNMixingHints($inks, new ColourComponentNames(new NameType('Orange')));
        $hints->validateComponents(new ColourComponentNames(new NameType('Orange')));
        self::assertSame($inks, $hints->get('Solidities'));
        $this->expectException(\InvalidArgumentException::class);
        $hints->validateComponents(new ColourComponentNames(new NameType('Other')));
    }

    public function testFunctionBasedAndFunctionArraysUseTrueDimensionsAndSourceDefinitions(): void
    {
        $space = $this->rgb();
        $functions = new ShadingFunctions($this->function(2, 1), $this->function(2, 1), $this->function(2, 1));
        $shading = new FunctionBasedShading($space, $functions);
        self::assertSame(1, $shading->shadingType());
        self::assertSame(193, $shading->definition()->source->pdfPage);
        self::assertInstanceOf(ArrayType::class, $shading->get('Function'));
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $shading->toPdf(new SerializationContext()));
    }

    public function testAxialProjectionAndEndpointExtensionFollowThePrintedFormula(): void
    {
        $shade = new AxialShading($this->rgb(), $this->point(10, 0), $this->point(30, 0), new ShadingFunctions($this->function(1, 3)));
        self::assertEqualsWithDelta(0.5, $shade->parameterAt($this->point(20, 100))->value, 1e-12);
        self::assertNull($shade->parameterAt($this->point(0, 0)));
        $extended = new AxialShading($this->rgb(), $this->point(10, 0), $this->point(30, 0), new ShadingFunctions($this->function(1, 3)), extend: new ShadingExtension(new BooleanType(true), new BooleanType(true)));
        self::assertSame(0, $extended->parameterAt($this->point(0, 0))->value);
        self::assertSame(1, $extended->parameterAt($this->point(100, 0))->value);
    }

    public function testRadialCircleInterpolationAndZeroRadiiAreSupported(): void
    {
        $radial = new RadialShading($this->rgb(), new Circle($this->point(10, 20), new NumericType(0)), new Circle($this->point(30, 40), new NumericType(100)), new ShadingFunctions($this->function(1, 3)));
        $circle = $radial->circleAt(new NumericType(0.5));
        self::assertEquals(20, $circle->centre->x->value);
        self::assertEquals(30, $circle->centre->y->value);
        self::assertEquals(50, $circle->radius->value);
        self::assertNull($radial->circleAt(new NumericType(2)));
    }

    public function testShadingFunctionsMustCoverTheShadingDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AxialShading($this->rgb(), $this->point(0, 0), $this->point(1, 0), new ShadingFunctions($this->function(1, 3)), new NumberInterval(new NumericType(0), new NumericType(2)));
    }

    public function testIndexedCannotBeUsedWithShadingFunctions(): void
    {
        $indexed = new Indexed($this->rgb(), new IntegerType(0), new HexadecimalString("\0\0\0"));
        $this->expectException(\InvalidArgumentException::class);
        new AxialShading($indexed, $this->point(0, 0), $this->point(1, 0), new ShadingFunctions($this->function(1, 1)));
    }

    public function testTriangleFlagsProduceExactByteAlignedRecordsAtOneBitWidths(): void
    {
        $a = $this->vertex(0, 0, 1);
        $b = $this->vertex(1, 0, 0);
        $c = $this->vertex(0, 1, 1);
        $mesh = new FreeFormMesh(new FreeFormTriangle($a, $b, $c), new TriangleContinuation(new IntegerType(1), $this->vertex(1, 1, 0)), new TriangleContinuation(new IntegerType(2), $a));
        self::assertSame('0820187088', bin2hex($mesh->bytes($this->format(1, 1, 2))));
        $triangles = $mesh->triangles();
        self::assertSame($b, $triangles[1]->a);
        self::assertSame($c, $triangles[1]->b);
        self::assertSame($b, $triangles[2]->a);
        self::assertSame($a, $triangles[2]->c);
    }

    public function testLatticeVerticesArePaddedIndividuallyAndTopologyIsDerived(): void
    {
        $mesh = new LatticeMesh(new IntegerType(2), $this->vertex(0, 0, 1), $this->vertex(1, 0, 0), $this->vertex(0, 1, 1), $this->vertex(1, 1, 0));
        self::assertSame('208060c0', bin2hex($mesh->bytes($this->format(1, 1, 2))));
        self::assertCount(2, $mesh->triangles());
        self::assertSame($mesh->vertices[1], $mesh->triangles()[1]->a);
    }

    public function testCoonsPatchFlagsArePackedContinuouslyAndReuseExactControls(): void
    {
        $patch = new CoonsPatch($this->controls(12), $this->corners());
        $continuation = new PatchContinuation(new IntegerType(1), $this->controls(8), new NumberArray(new NumericType(1)), new NumberArray(new NumericType(0)));
        $mesh = new PatchMesh($patch, $continuation);
        self::assertSame('00000029000080', bin2hex($mesh->bytes($this->format(1, 1, 2))));
        self::assertSame($patch->controls->points[3], $mesh->patches()[1]->controls->points[0]);
        self::assertSame($patch->controls->points[6], $mesh->patches()[1]->controls->points[3]);
        self::assertSame($patch->colours->second, $mesh->patches()[1]->colours->first);
        self::assertSame($patch->colours->third, $mesh->patches()[1]->colours->second);
    }

    public function testTensorPatchFlagsPackSixteenControlsAndTwelveNewControls(): void
    {
        $mesh = new PatchMesh(new TensorProductPatch($this->controls(16), $this->corners()), new PatchContinuation(new IntegerType(1), $this->controls(12), new NumberArray(new NumericType(1)), new NumberArray(new NumericType(0))));
        self::assertSame('000000002900000080', bin2hex($mesh->bytes($this->format(1, 1, 2))));
        self::assertSame(7, $mesh->type);
    }

    public function testAllMeshTypesAreActualStreamObjectsWithRequiredDictionaries(): void
    {
        $space = new DeviceColourSpace(ColourSpaceFamily::DeviceGray);
        $format = $this->format(16, 8, 8);
        $objects = [new FreeFormGouraudShading($space, new FreeFormMesh(new FreeFormTriangle($this->vertex(0, 0, 1), $this->vertex(1, 0, 0), $this->vertex(0, 1, 1))), $format), new LatticeGouraudShading($space, new LatticeMesh(new IntegerType(2), $this->vertex(0, 0, 1), $this->vertex(1, 0, 0), $this->vertex(0, 1, 1), $this->vertex(1, 1, 0)), $format), new CoonsPatchShading($space, new PatchMesh(new CoonsPatch($this->controls(12), $this->corners())), $format), new TensorProductPatchShading($space, new PatchMesh(new TensorProductPatch($this->controls(16), $this->corners())), $format)];
        foreach ($objects as $index => $object) {
            self::assertSame(4 + $index, $object->shadingType());
            self::assertNotEmpty($object->data);
            self::assertStringContainsString('/ShadingType ' . (4 + $index), $object->toPdf(new SerializationContext()));
            self::assertSame(16, $object->dictionary()->get('BitsPerCoordinate')->value);
        }
    }

    public function testMeshFunctionChangesDecodeAndVertexComponentsToOneParameter(): void
    {
        $functions = new ShadingFunctions($this->function(1, 3));
        $mesh = new FreeFormMesh(new FreeFormTriangle($this->vertex(0, 0, 1), $this->vertex(1, 0, 0), $this->vertex(0, 1, 1)));
        $shade = new FreeFormGouraudShading($this->rgb(), $mesh, $this->format(16, 8, 8), $functions);
        self::assertInstanceOf(IndirectReference::class, $shade->dictionary()->get('Function'));
        self::assertCount(6, $shade->dictionary()->get('Decode'));
    }

    public function testDecodePreservesReverseAndConstantIntervals(): void
    {
        $reverse = new DecodeInterval(new NumericType(1), new NumericType(-1));
        self::assertSame(0, $reverse->encode(new NumericType(1), 8));
        self::assertSame(255, $reverse->encode(new NumericType(-1), 8));
        self::assertEquals(-1, $reverse->decode(255, 8)->value);
        self::assertSame(0, (new DecodeInterval(new NumericType(5), new NumericType(5)))->encode(new NumericType(5), 8));
    }

    public function testFiniteExtremeIntervalsDoNotOverflowIntermediateArithmetic(): void
    {
        $decode = new DecodeInterval(new NumericType(-1e308), new NumericType(1e308));
        self::assertSame(128, $decode->encode(new NumericType(0), 8));
        self::assertEquals(-1e308, $decode->decode(0, 8)->value);
        self::assertEquals(1e308, $decode->decode(255, 8)->value);
        $axial = new AxialShading($this->rgb(), $this->point(-1e308, 0), $this->point(1e308, 0), new ShadingFunctions($this->function(1, 3)));
        self::assertSame(0.5, $axial->parameterAt($this->point(0, 0))->value);
    }

    public function testPatchSurfacesFollowBoundaryAndInteriorGeometry(): void
    {
        $boundary = [[$this->point(0, 0)], [$this->point(0, 1 / 3)], [$this->point(0, 2 / 3)], [$this->point(0, 1)], [$this->point(1 / 3, 1)], [$this->point(2 / 3, 1)], [$this->point(1, 1)], [$this->point(1, 2 / 3)], [$this->point(1, 1 / 3)], [$this->point(1, 0)], [$this->point(2 / 3, 0)], [$this->point(1 / 3, 0)]];
        $points = array_map(static fn (array $point): Point => $point[0], $boundary);
        $coons = new CoonsPatch(new PatchControlPoints(...$points), $this->corners());
        $tensor = new TensorProductPatch(new PatchControlPoints(...[...$points, $this->point(1 / 3, 1 / 3), $this->point(1 / 3, 2 / 3), $this->point(2 / 3, 2 / 3), $this->point(2 / 3, 1 / 3)]), $this->corners());
        foreach ([$coons, $tensor] as $patch) {
            $point = $patch->pointAt(new NumericType(0.25), new NumericType(0.75));
            self::assertEqualsWithDelta(0.25, $point->x->value, 1e-12);
            self::assertEqualsWithDelta(0.75, $point->y->value, 1e-12);
            self::assertEquals(0, $patch->pointAt(new NumericType(0), new NumericType(0))->x->value);
            self::assertEquals(1, $patch->pointAt(new NumericType(1), new NumericType(1))->y->value);
        }
        $warped = [...$points, $this->point(2 / 3, 1 / 3), $this->point(1 / 3, 2 / 3), $this->point(2 / 3, 2 / 3), $this->point(2 / 3, 1 / 3)];
        $centre = (new TensorProductPatch(new PatchControlPoints(...$warped), $this->corners()))->pointAt(new NumericType(0.5), new NumericType(0.5));
        self::assertEqualsWithDelta(0.5 + 3 / 64, $centre->x->value, 1e-12);
        self::assertEqualsWithDelta(0.5, $centre->y->value, 1e-12);
        self::assertEqualsWithDelta(0.5, $this->corners()->at(new NumericType(0.5), new NumericType(0.5))->numbers[0]->value, 1e-12);
    }

    public function testEveryPatchReuseEdgePreservesItsFourControlsAndCornerColours(): void
    {
        $patch = new CoonsPatch(new PatchControlPoints(...array_map(fn (int $i): Point => $this->point($i, 0), range(0, 11))), $this->corners());
        foreach ([1 => [3, 6], 2 => [6, 9], 3 => [9, 0]] as $flag => [$first, $last]) {
            $mesh = new PatchMesh($patch, new PatchContinuation(new IntegerType($flag), $this->controls(8), new NumberArray(new NumericType(0)), new NumberArray(new NumericType(0))));
            self::assertSame($patch->controls->points[$first], $mesh->patches()[1]->controls->points[0]);
            self::assertSame($patch->controls->points[$last], $mesh->patches()[1]->controls->points[3]);
            self::assertSame($patch->colours->corners()[$flag], $mesh->patches()[1]->colours->first);
        }
    }

    public function testStencilFontCannotHideAColouredTypeThreeGlyph(): void
    {
        $glyph = new StreamObject(new ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\SetGlyphWidth(new NumericType(1), new NumericType(0))));
        $font = new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Type3')), new Entry(new NameType('CharProcs'), new DictionaryType(new Entry(new NameType('A'), new IndirectReference($glyph)))));
        $resources = new ResourceDictionary(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
        $this->expectException(\InvalidArgumentException::class);
        new TilingPattern(new ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize(new NameType('F1'), new NumericType(12))), PaintType::Uncoloured, TilingType::NoDistortion, $this->box(), new NumericType(8), new NumericType(8), $resources);
    }

    public function testStencilFontAcceptsExplicitTypedStencilGlyphs(): void
    {
        $glyph = new StreamObject(new ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\SetGlyphWidthAndBoundingBox(new NumericType(1), new NumericType(0), new NumericType(0), new NumericType(0), new NumericType(1), new NumericType(1)), new AppendRectangle(new NumericType(0), new NumericType(0), new NumericType(1), new NumericType(1)), new FillPathNonzero()));
        $font = new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Type3')), new Entry(new NameType('CharProcs'), new DictionaryType(new Entry(new NameType('A'), new IndirectReference($glyph)))));
        $resources = new ResourceDictionary(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
        $tile = new TilingPattern(new ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize(new NameType('F1'), new NumericType(12))), PaintType::Uncoloured, TilingType::NoDistortion, $this->box(), new NumericType(8), new NumericType(8), $resources);
        self::assertSame(2, $tile->dictionary()->get('PaintType')->value);
    }

    public function testNchannelCanUseACmykProcessSubsetWithAnExplicitIccPayload(): void
    {
        // Profile identity and true ICC internals remain the external supplier's
        // contract. The PDF process declaration has four CMYK components.
        $cmyk = new ICCBased(new ICCProfile('externally supplied CMYK profile', new IntegerType(4)));
        $process = new DeviceNProcess($cmyk, new ColourComponentNames(new NameType('Cyan'), new NameType('Magenta'), new NameType('Yellow'), new NameType('Black')));
        $space = new DeviceN(new ColourComponentNames(new NameType('Cyan'), new NameType('Black')), new TintTransform($this->function(2, 3), 2, $this->rgb()), new DeviceNAttributes(new NameType('NChannel'), process: $process));
        self::assertSame(2, $space->componentCount());
        self::assertTrue($process->isCMYK());
    }

    #[DataProvider('coordinateWidths')]
    public function testEveryAllowedCoordinateBitWidthSupportsItsUnsignedMaximum(int $bits): void
    {
        $interval = new DecodeInterval(new NumericType(0), new NumericType(1));
        $encoded = $interval->encode(new NumericType(1), $bits);
        self::assertSame((1 << $bits) - 1, $encoded);
        $writer = new MeshBitWriter();
        $writer->write($encoded, $bits);
        self::assertSame((int) ceil($bits / 8), strlen($writer->bytes()));
        self::assertEqualsWithDelta(1, $interval->decode($encoded, $bits)->value, 1e-12);
    }

    public static function coordinateWidths(): array
    {
        return array_map(static fn (int $bits): array => [$bits], [1, 2, 4, 8, 12, 16, 24, 32]);
    }

    public function testIncompleteTriangleAndLatticeAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FreeFormMesh(new TriangleContinuation(new IntegerType(1), $this->vertex(0, 0, 0)));
    }

    public function testPartialLatticeRowIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LatticeMesh(new IntegerType(2), $this->vertex(0, 0, 0), $this->vertex(1, 0, 0), $this->vertex(0, 1, 0));
    }

    public function testPatchReuseCannotAppearWithoutAnInitialPatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PatchMesh(new PatchContinuation(new IntegerType(1), $this->controls(8), new NumberArray(new NumericType(0)), new NumberArray(new NumericType(0))));
    }

    public function testBinaryOutputLimitFailsWithoutAllocatingUnboundedOutput(): void
    {
        $writer = new MeshBitWriter(1);
        $this->expectException(\LengthException::class);
        $writer->write(65535, 16);
    }

    public function testTilingSupportsNegativeStepsAndStencilSelection(): void
    {
        $pattern = $this->tile();
        self::assertSame(-8, $pattern->dictionary()->get('XStep')->value);
        $rgb = $this->rgb();
        $colour = new PatternColour(new Pattern($rgb), new NameType('Tile'), $pattern, new ColourComponents($rgb, new NumericType(1), new NumericType(0), new NumericType(0)));
        self::assertSame('1 0 0 /Tile scn', $colour->nonstroking()->toPdf(new SerializationContext()));
    }

    public function testStencilCellsRejectDirectColourOperators(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TilingPattern(new ContentStreams(new SetNonstrokingRGBColor(new NumericType(1), new NumericType(0), new NumericType(0))), PaintType::Uncoloured, TilingType::NoDistortion, $this->box(), new NumericType(8), new NumericType(8), new ResourceDictionary());
    }

    public function testStencilCellsCannotHideColourOperatorsInInvokedForms(): void
    {
        $form = new StreamObject(new ContentStreams(new SetNonstrokingRGBColor(new NumericType(1), new NumericType(0), new NumericType(0))), new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Form'))));
        $resources = new ResourceDictionary(new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Fm'), new IndirectReference($form)))));
        $this->expectException(\InvalidArgumentException::class);
        new TilingPattern(new ContentStreams(new InvokeXObject(new NameType('Fm'))), PaintType::Uncoloured, TilingType::NoDistortion, $this->box(), new NumericType(8), new NumericType(8), $resources);
    }

    public function testColouredPatternRejectsStencilColourComponents(): void
    {
        $shading = new AxialShading($this->rgb(), $this->point(0, 0), $this->point(1, 0), new ShadingFunctions($this->function(1, 3)));
        $this->expectException(\InvalidArgumentException::class);
        new PatternColour(new Pattern($this->rgb()), new NameType('Gradient'), new ShadingPattern($shading));
    }

    private function white(): WhitePoint
    {
        return new WhitePoint(new NumericType(0.9505), new NumericType(1), new NumericType(1.089));
    }

    private function rgb(): DeviceColourSpace
    {
        return new DeviceColourSpace(ColourSpaceFamily::DeviceRGB);
    }

    private function function(int $inputs, int $outputs): PostScriptCalculatorFunction
    {
        $instructions = array_fill(0, $inputs, CalculatorOperator::Pop);
        for ($index = 0; $index < $outputs; ++$index) {
            $instructions[] = new Number(new NumericType(0));
        }
        $unit = new NumberInterval(new NumericType(0), new NumericType(1));
        return new PostScriptCalculatorFunction(new Domain(...array_fill(0, $inputs, $unit)), new Range(...array_fill(0, $outputs, $unit)), new Procedure(...$instructions));
    }

    private function point(int|float $x, int|float $y): Point
    {
        return new Point(new NumericType($x), new NumericType($y));
    }

    private function vertex(int $x, int $y, int $gray): MeshVertex
    {
        return new MeshVertex($this->point($x, $y), new NumberArray(new NumericType($gray)));
    }

    private function format(int $coordinate, int $component, int $flag): MeshFormat
    {
        $unit = new DecodeInterval(new NumericType(0), new NumericType(1));
        return new MeshFormat(new MeshDecode($unit, $unit, $unit), new MeshBitDepths(new IntegerType($coordinate), new IntegerType($component), new IntegerType($flag)));
    }

    private function controls(int $count): PatchControlPoints
    {
        return new PatchControlPoints(...array_fill(0, $count, $this->point(0, 0)));
    }

    private function corners(): PatchColours
    {
        return new PatchColours(new NumberArray(new NumericType(1)), new NumberArray(new NumericType(0)), new NumberArray(new NumericType(1)), new NumberArray(new NumericType(0)));
    }

    private function box(): Rectangle
    {
        return new Rectangle(new NumericType(0), new NumericType(0), new NumericType(8), new NumericType(8));
    }

    private function tile(): TilingPattern
    {
        return new TilingPattern(new ContentStreams(new AppendRectangle(new NumericType(0), new NumericType(0), new NumericType(4), new NumericType(4)), new FillPathNonzero()), PaintType::Uncoloured, TilingType::NoDistortion, $this->box(), new NumericType(-8), new NumericType(8), new ResourceDictionary());
    }
}
