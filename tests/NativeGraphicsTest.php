<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Images\DeviceColorSpace;
use PDF\Document\Images\ImageDimensions;
use PDF\Document\Images\ImageSamples;
use PDF\Document\Images\SampleLayout;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Functions\Domain;
use PDF\Functions\ExponentialInterpolationFunction;
use PDF\Functions\NumberArray;
use PDF\Functions\NumberInterval;
use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Graphics\ColourSpaces\DeviceColourSpace;
use PDF\Graphics\ColourSpaces\Indexed;
use PDF\Graphics\GraphicsState\BlendFunction;
use PDF\Graphics\GraphicsState\BlendMode;
use PDF\Graphics\GraphicsState\BlendModes;
use PDF\Graphics\GraphicsState\BlendingColourSpace;
use PDF\Graphics\GraphicsState\ColourFunctionName;
use PDF\Graphics\GraphicsState\GraphicsStateParameters;
use PDF\Graphics\GraphicsState\GraphicsStateScope;
use PDF\Graphics\GraphicsState\ParameterState;
use PDF\Graphics\GraphicsState\SoftMask;
use PDF\Graphics\GraphicsState\SoftMaskSubtype;
use PDF\Graphics\Halftones\ColourHalftone;
use PDF\Graphics\Halftones\HalftoneComponent;
use PDF\Graphics\Halftones\HalftoneOptions;
use PDF\Graphics\Halftones\RectangularHalftone;
use PDF\Graphics\Halftones\ScreenHalftone;
use PDF\Graphics\Halftones\SixteenBitHalftone;
use PDF\Graphics\Halftones\SpotFunction;
use PDF\Graphics\Halftones\SpotFunctionName;
use PDF\Graphics\Halftones\ThresholdArray;
use PDF\Graphics\Halftones\ThresholdRectangle;
use PDF\Graphics\Halftones\TwoSquareHalftone;
use PDF\Graphics\Patterns\Shadings\DecodeInterval;
use PDF\Graphics\Patterns\TilingPattern;
use PDF\Graphics\Patterns\PaintType;
use PDF\Graphics\Patterns\TilingType;
use PDF\Graphics\XObjects\AlternateImage;
use PDF\Graphics\XObjects\AlternateImages;
use PDF\Graphics\XObjects\ColourKeyMask;
use PDF\Graphics\XObjects\EmbeddedImageAlpha;
use PDF\Graphics\XObjects\EncodedImageXObject;
use PDF\Graphics\XObjects\FormXObject;
use PDF\Graphics\XObjects\ImageDecode;
use PDF\Graphics\XObjects\ImageMask;
use PDF\Graphics\XObjects\ImageXObject;
use PDF\Graphics\XObjects\MatteColour;
use PDF\Graphics\XObjects\ReferenceDictionary;
use PDF\Graphics\XObjects\SamplePacker;
use PDF\Graphics\XObjects\SoftMaskImage;
use PDF\Graphics\XObjects\TransparencyGroup;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Type3GlyphContentStream;
use PDF\Syntax\ContentStreamsResources\Operators\SetGraphicsStateParameters;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\SetGlyphWidth;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingColorSpace;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\FilterName;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeGraphicsTest extends TestCase
{
    #[DataProvider('imageBitDepths')]
    public function testImageRowsArePackedAndDecodedAtEveryNativeDepth(int $bits, string $hex): void
    {
        $maximum = (1 << $bits) - 1;
        $dimensions = new ImageDimensions(3, 2);
        $samples = (new SamplePacker())->pack($dimensions, new SampleLayout(DeviceColorSpace::Gray, $bits), [0, $maximum, 0, $maximum, 0, $maximum]);
        self::assertSame($hex, bin2hex($samples->bytes));
        self::assertSame($maximum, $samples->sample(0, 1, 0));
        self::assertSame($dimensions->sampleBytes($samples->layout), strlen($samples->bytes));
        $image = new ImageXObject($samples);
        self::assertEquals(1, $image->colourAt(0, 1)->numbers[0]->value);
        self::assertSame($bits, $image->dictionary()->get('BitsPerComponent')->value);
    }

    public static function imageBitDepths(): array
    {
        return [[1, '40a0'], [2, '30cc'], [4, '0f00f0f0'], [8, '00ff00ff00ff'], [16, '0000ffff0000ffff0000ffff']];
    }

    public function testImagePackingDoesNotLetPaddingBleedBetweenRgbRows(): void
    {
        $samples = (new SamplePacker())->pack(new ImageDimensions(1, 2), new SampleLayout($this->rgb(), 1), [1, 0, 1, 0, 1, 0]);
        self::assertSame('a040', bin2hex($samples->bytes));
        self::assertSame(1, $samples->sample(0, 1, 1));
    }

    public function testImageDecodePreservesRawMappingAndClipsTheActualColour(): void
    {
        $decode = new ImageDecode(new DecodeInterval(new NumericType(2), new NumericType(-1)));
        $image = new ImageXObject($this->graySamples(), $decode);
        self::assertEquals(2, $image->decodedAt(0, 0)->numbers[0]->value);
        self::assertEquals(1, $image->colourAt(0, 0)->numbers[0]->value);
    }

    public function testIndexedImageDecodeDefaultsToSampleMaximumRatherThanHighValue(): void
    {
        $indexed = new Indexed($this->rgb(), new IntegerType(1), new HexadecimalString("\xFF\0\0\0\xFF\0"));
        $samples = (new SamplePacker())->pack(new ImageDimensions(1, 1), new SampleLayout($indexed, 2), [3]);
        $image = new ImageXObject($samples);
        self::assertEquals(3, $image->decode->intervals[0]->last->value);
        self::assertEquals(1, $image->colourAt(0, 0)->numbers[0]->value);
    }

    public function testImageSampleArityAndOutputLimitsAreEnforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SamplePacker())->pack(new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::RGB), [0, 0]);
    }

    public function testSixteenBitImagesRequirePdf15InTheActualWriter(): void
    {
        $image = new ImageXObject((new SamplePacker())->pack(new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::Gray, 16), [32768]));
        $this->expectException(\LogicException::class);
        $this->file($image, '1.4')->toPdf();
    }

    public function testEffectiveCatalogVersionPermitsSixteenBitImages(): void
    {
        $image = new ImageXObject((new SamplePacker())->pack(new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::Gray, 16), [32768]));
        self::assertStringContainsString('/BitsPerComponent 16', $this->file($image, '1.4', '1.5')->toPdf());
    }

    public function testSoftMaskImageRequiresPdf14InTheActualWriter(): void
    {
        $image = new ImageXObject($this->graySamples(), softMask: new SoftMaskImage($this->graySamples()));
        $this->expectException(\LogicException::class);
        $this->file($image, '1.3')->toPdf();
    }

    public function testImageSampleOutputLimitIsCheckedBeforeConsumption(): void
    {
        $this->expectException(\LengthException::class);
        (new SamplePacker(1))->pack(new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::RGB), []);
    }

    public function testImageMasksPaintZeroSamplesAndInversionPaintsOneSamples(): void
    {
        $samples = (new SamplePacker())->pack(new ImageDimensions(2, 1), new SampleLayout(DeviceColorSpace::Gray, 1), [0, 1]);
        $normal = new ImageMask($samples);
        $inverse = new ImageMask($samples, new BooleanType(true));
        self::assertTrue($normal->paints(0, 0));
        self::assertFalse($normal->paints(1, 0));
        self::assertTrue($inverse->paints(1, 0));
        self::assertFalse($normal->dictionary()->has('ColorSpace'));
        self::assertStringContainsString('/ImageMask true', $normal->toPdf(new SerializationContext()));
    }

    public function testColourKeyMasksCompareInclusiveUndecodedSamples(): void
    {
        $mask = new ColourKeyMask(new NumberInterval(new IntegerType(0), new IntegerType(10)), new NumberInterval(new IntegerType(5), new IntegerType(15)), new NumberInterval(new IntegerType(0), new IntegerType(255)));
        self::assertTrue($mask->masks(10, 15, 255));
        self::assertFalse($mask->masks(10, 16, 255));
        $mask->validate(3, 8);
        $this->expectException(\InvalidArgumentException::class);
        $mask->validate(3, 4);
    }

    public function testSoftMaskDimensionsAreIndependentUntilMatteIsDeclared(): void
    {
        $rgb = $this->rgb();
        $parent = new ImageSamples(new ImageDimensions(2, 1), new SampleLayout($rgb), "\0\0\0\0\0\0");
        $independent = new SoftMaskImage($this->graySamples());
        self::assertSame($independent, (new ImageXObject($parent, softMask: $independent))->dictionary()->get('SMask')->target());
        $matte = new SoftMaskImage($this->graySamples(), new MatteColour($this->colour($rgb, 1, 1, 1)));
        $this->expectException(\InvalidArgumentException::class);
        new ImageXObject($parent, softMask: $matte);
    }

    public function testMatteRoundTripUsesActualColourValuesAndAlpha(): void
    {
        $rgb = $this->rgb();
        $matte = new MatteColour($this->colour($rgb, 1, 1, 1));
        $source = $this->colour($rgb, 0.2, 0.4, 0.6);
        $preblended = $matte->preblend($source, new NumericType(0.5));
        self::assertEqualsWithDelta(0.6, $preblended->numbers[0]->value, 1e-12);
        $restored = $matte->unblend($preblended, new NumericType(0.5));
        foreach ($source->numbers as $index => $number) {
            self::assertEqualsWithDelta($number->value, $restored->numbers[$index]->value, 1e-12);
        }
        $this->expectException(\LogicException::class);
        $matte->unblend($preblended, new NumericType(0));
    }

    public function testEncodedJpxKeepsOptionalEmbeddedMetadataAndAlphaDeclaration(): void
    {
        $image = new EncodedImageXObject('external JPEG2000 codec bytes', new ImageDimensions(1, 1), new EncodedData(FilterName::JPXDecode), embeddedAlpha: EmbeddedImageAlpha::Preblended);
        self::assertSame(2, $image->dictionary()->get('SMaskInData')->value);
        self::assertFalse($image->dictionary()->has('ColorSpace'));
        self::assertFalse($image->dictionary()->has('BitsPerComponent'));
    }

    public function testEncodedSampleDepthMustMatchTheCodecDeclaration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncodedImageXObject('external JPEG bytes', new ImageDimensions(1, 1), new EncodedData(FilterName::DCTDecode), $this->rgb(), new IntegerType(16));
    }

    public function testEmbeddedAlphaCannotCoexistWithAnExplicitSoftMask(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncodedImageXObject('external JPEG2000 bytes', new ImageDimensions(1, 1), new EncodedData(FilterName::JPXDecode), softMask: new SoftMaskImage($this->graySamples()), embeddedAlpha: EmbeddedImageAlpha::Opacity);
    }

    public function testOnlyOneAlternateCanBeThePrintingDefault(): void
    {
        $image = new ImageXObject($this->graySamples());
        $this->expectException(\InvalidArgumentException::class);
        new AlternateImages(new AlternateImage($image, new BooleanType(true)), new AlternateImage($image, new BooleanType(true)));
    }

    public function testStencilCannotHideAColouredImageInAnAlternate(): void
    {
        $colour = new ImageXObject($this->graySamples());
        $stencil = new ImageMask((new SamplePacker())->pack(new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::Gray, 1), [0]), options: new \PDF\Graphics\XObjects\ImageOptions(alternates: new AlternateImages(new AlternateImage($colour))));
        $resources = new ResourceDictionary(new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Image'), new IndirectReference($stencil)))));
        $this->expectException(\InvalidArgumentException::class);
        new TilingPattern(new ContentStreams(new InvokeXObject(new NameType('Image'))), PaintType::Uncoloured, TilingType::NoDistortion, $this->box(), new NumericType(1), new NumericType(1), $resources);
    }

    public function testStencilPermitsTheOverprintParametersNotProhibitedByTheSource(): void
    {
        $state = new GraphicsStateParameters(new Entry(new NameType('OP'), new BooleanType(true)), new Entry(new NameType('OPM'), new IntegerType(1)));
        $resources = new ResourceDictionary(new Entry(new NameType('ExtGState'), new DictionaryType(new Entry(new NameType('State'), new IndirectReference($state)))));
        $pattern = new TilingPattern(new ContentStreams(new SetGraphicsStateParameters(new NameType('State'))), PaintType::Uncoloured, TilingType::NoDistortion, $this->box(), new NumericType(1), new NumericType(1), $resources);
        self::assertSame(2, $pattern->dictionary()->get('PaintType')->value);
    }

    public function testStencilPermitsD0GlyphsWhoseActualProgramDoesNotSpecifyColour(): void
    {
        $glyph = new StreamObject(new Type3GlyphContentStream(new SetGlyphWidth(new NumericType(1), new NumericType(0))));
        $font = new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Type3')), new Entry(new NameType('CharProcs'), new DictionaryType(new Entry(new NameType('A'), new IndirectReference($glyph)))));
        $resources = new ResourceDictionary(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
        $pattern = new TilingPattern(new ContentStreams(new SetTextFontAndSize(new NameType('F1'), new NumericType(10))), PaintType::Uncoloured, TilingType::NoDistortion, $this->box(), new NumericType(1), new NumericType(1), $resources);
        self::assertSame(2, $pattern->dictionary()->get('PaintType')->value);
    }

    public function testFormResourceBindingsAreRequiredAndArityFollowsItsSelectedSpace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormXObject(new ContentStreams(new InvokeXObject(new NameType('Missing'))), $this->box(), new ResourceDictionary());
    }

    public function testFormCannotSpecifyTwoRgbComponentsAfterSelectingRgb(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormXObject(new ContentStreams(new SetNonstrokingColorSpace(new NameType('DeviceRGB')), new SetNonstrokingColor(new NumericType(0), new NumericType(0))), $this->box(), new ResourceDictionary());
    }

    public function testReferenceFormsKeepWeakPageReferencesWithoutReadingFiles(): void
    {
        $reference = new ReferenceDictionary(new LiteralString('external.pdf'), new TextString('A-1'), new ArrayType(new HexadecimalString('first id'), new HexadecimalString('second id')));
        $form = new FormXObject(new ContentStreams(), $this->box(), new ResourceDictionary(), reference: $reference);
        self::assertSame($reference, $form->dictionary()->get('Ref'));
        self::assertStringContainsString('/Page (A-1)', $form->toPdf(new SerializationContext()));
    }

    public function testFormStructuralParentKeysAreMutuallyExclusive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormXObject(new ContentStreams(), $this->box(), new ResourceDictionary(), structParent: new IntegerType(0), structParents: new IntegerType(1));
    }

    public function testGroupColourSpaceRequiresIsolationForForms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormXObject(new ContentStreams(), $this->box(), new ResourceDictionary(), group: new TransparencyGroup(new BlendingColourSpace($this->rgb())));
    }

    public function testLuminosityMaskRequiresAnExplicitGroupColourSpace(): void
    {
        $group = new FormXObject(new ContentStreams(), $this->box(), new ResourceDictionary(), group: new TransparencyGroup());
        $this->expectException(\InvalidArgumentException::class);
        new SoftMask(SoftMaskSubtype::Luminosity, $group);
    }

    public function testLuminosityMaskReferencesTheActualGroupAndTransfer(): void
    {
        $rgb = $this->rgb();
        $group = new FormXObject(new ContentStreams(), $this->box(), new ResourceDictionary(), group: new TransparencyGroup(new BlendingColourSpace($rgb), new BooleanType(true)));
        $mask = new SoftMask(SoftMaskSubtype::Luminosity, $group, $this->colour($rgb, 0, 0, 0), $this->function());
        self::assertSame($group, $mask->get('G')->target());
        self::assertSame('Luminosity', $mask->get('S')->value);
    }

    #[DataProvider('separableModes')]
    public function testEverySeparableBlendModeFollowsItsSourceFormula(BlendMode $mode, float $expected): void
    {
        $rgb = $this->rgb();
        $blend = new BlendFunction($mode, new BlendingColourSpace($rgb));
        self::assertEqualsWithDelta($expected, $blend->blend($this->colour($rgb, 0.2, 0.2, 0.2), $this->colour($rgb, 0.8, 0.8, 0.8))->numbers[0]->value, 1e-12);
    }

    public static function separableModes(): array
    {
        return [[BlendMode::Normal, 0.8], [BlendMode::Compatible, 0.8], [BlendMode::Multiply, 0.16], [BlendMode::Screen, 0.84], [BlendMode::Overlay, 0.32], [BlendMode::Darken, 0.2], [BlendMode::Lighten, 0.8], [BlendMode::ColorDodge, 1.0], [BlendMode::ColorBurn, 0.0], [BlendMode::HardLight, 0.68], [BlendMode::SoftLight, 0.3488], [BlendMode::Difference, 0.6], [BlendMode::Exclusion, 0.68]];
    }

    public function testNonseparableModesPreserveOrReplaceLuminosityAsSpecified(): void
    {
        $rgb = $this->rgb();
        $b = $this->colour($rgb, 0.2, 0.4, 0.6);
        $s = $this->colour($rgb, 0.8, 0.3, 0.1);
        $lum = static fn (ColourComponents $c): float => 0.3 * $c->numbers[0]->value + 0.59 * $c->numbers[1]->value + 0.11 * $c->numbers[2]->value;
        foreach ([BlendMode::Hue, BlendMode::Saturation, BlendMode::Color, BlendMode::Luminosity] as $mode) {
            $result = (new BlendFunction($mode, new BlendingColourSpace($rgb)))->blend($b, $s);
            self::assertEqualsWithDelta($lum($mode === BlendMode::Luminosity ? $s : $b), $lum($result), 1e-12);
        }
    }

    public function testSubtractiveBlendingComplementsAllComponentsAndPreservesCmykBlackRules(): void
    {
        $cmyk = new DeviceColourSpace(ColourSpaceFamily::DeviceCMYK);
        $b = $this->colour($cmyk, 0.2, 0.4, 0.6, 0.25);
        $s = $this->colour($cmyk, 0.8, 0.3, 0.1, 0.75);
        $multiply = (new BlendFunction(BlendMode::Multiply, new BlendingColourSpace($cmyk)))->blend($b, $s);
        self::assertEqualsWithDelta(0.84, $multiply->numbers[0]->value, 1e-12);
        foreach ([BlendMode::Hue, BlendMode::Saturation, BlendMode::Color, BlendMode::Luminosity] as $mode) {
            self::assertEquals($mode === BlendMode::Luminosity ? 0.75 : 0.25, (new BlendFunction($mode, new BlendingColourSpace($cmyk)))->blend($b, $s)->numbers[3]->value);
        }
    }

    public function testCompositingNormalizesColourAndKeepsUndefinedZeroAlphaExplicit(): void
    {
        $rgb = $this->rgb();
        $blend = new BlendFunction(BlendMode::Normal, new BlendingColourSpace($rgb));
        $b = $this->colour($rgb, 0, 0, 1);
        $s = $this->colour($rgb, 1, 0, 0);
        $result = $blend->composite($b, new NumericType(0.5), $s, new NumericType(0.5));
        self::assertSame(0.75, $result->alpha->value);
        self::assertEqualsWithDelta(2 / 3, $result->colour->numbers[0]->value, 1e-12);
        self::assertNull($blend->composite($b, new NumericType(0), $s, new NumericType(0))->colour);
        self::assertSame(BlendMode::Screen, (new BlendModes(new NameType('FutureBlend'), BlendMode::Screen))->standard());
    }

    public function testExtGStatePrecedenceAndOverprintDoNotDependOnEntryOrder(): void
    {
        $initial = new ParameterState(new Entry(new NameType('TR'), new NameType('Identity')), new Entry(new NameType('op'), new BooleanType(false)));
        $scope = new GraphicsStateScope($initial);
        $scope->save();
        $scope->apply(new GraphicsStateParameters(new Entry(new NameType('TR2'), new NameType('Default')), new Entry(new NameType('TR'), new IndirectReference($this->function())), new Entry(new NameType('OP'), new BooleanType(true))));
        self::assertSame('Identity', $scope->current()->get('TR')->value);
        self::assertTrue($scope->current()->get('op')->value);
        $scope->apply(new GraphicsStateParameters(new Entry(new NameType('OP'), new BooleanType(false)), new Entry(new NameType('op'), new BooleanType(true))));
        self::assertFalse($scope->current()->get('OP')->value);
        self::assertTrue($scope->current()->get('op')->value);
        $scope->restore();
        self::assertSame($initial, $scope->current());
    }

    #[DataProvider('invalidStateParameters')]
    public function testExtGStateRejectsUndefinedCodesAndOutOfRangeValues(string $key, NumericType $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GraphicsStateParameters(new Entry(new NameType($key), $value));
    }

    public static function invalidStateParameters(): array
    {
        return [['LW', new NumericType(-1)], ['ML', new NumericType(0)], ['LC', new IntegerType(3)], ['LJ', new IntegerType(-1)], ['OPM', new IntegerType(2)], ['CA', new NumericType(1.1)], ['ca', new NumericType(-0.1)], ['SM', new NumericType(2)], ['FL', new NumericType(101)]];
    }

    public function testThresholdHalftonesWriteActualByteArraysAndConditionalRectangles(): void
    {
        $first = new ThresholdRectangle(new ImageDimensions(2, 1), new ThresholdArray(16, 2, [0x1234, 0xFFFF]));
        $second = new ThresholdRectangle(new ImageDimensions(1, 1), new ThresholdArray(16, 1, [0xABCD]));
        $sixteen = new SixteenBitHalftone($first, $second);
        self::assertSame('1234ffffabcd', bin2hex($sixteen->data));
        self::assertSame(1, $sixteen->dictionary()->get('Width2')->value);
        self::assertSame(0xFFFF, $first->thresholds->sample(1));
        $six = new RectangularHalftone(new ThresholdRectangle(new ImageDimensions(2, 2), new ThresholdArray(8, 4, [0, 85, 170, 255])));
        self::assertSame('0055aaff', bin2hex($six->data));
        $ten = new TwoSquareHalftone(new IntegerType(2), new IntegerType(1), new ThresholdArray(8, 5, [0, 1, 2, 3, 4]));
        self::assertSame('0001020304', bin2hex($ten->data));
        self::assertStringContainsString('/HalftoneType 16', $sixteen->toPdf(new SerializationContext()));
    }

    public function testTypeFiveSpotComponentsRequireTransferOnBothSpotAndDefault(): void
    {
        $plain = new ScreenHalftone(new NumericType(80), new NumericType(15), new SpotFunction(SpotFunctionName::Round));
        $transferred = new ScreenHalftone(new NumericType(80), new NumericType(30), new SpotFunction(SpotFunctionName::Round), new HalftoneOptions(transfer: ColourFunctionName::Identity));
        $colour = new ColourHalftone($transferred, null, new HalftoneComponent(new NameType('Orange'), $transferred));
        self::assertSame($transferred, $colour->forColorant(new NameType('Unknown')));
        self::assertSame(5, $colour->halftoneType());
        $this->expectException(\InvalidArgumentException::class);
        new ColourHalftone($plain, null, new HalftoneComponent(new NameType('Orange'), $transferred));
    }

    public function testTypeFiveCannotContainTypeFive(): void
    {
        $screen = new ScreenHalftone(new NumericType(80), new NumericType(0), new SpotFunction(SpotFunctionName::Round));
        $this->expectException(\InvalidArgumentException::class);
        new ColourHalftone(new ColourHalftone($screen));
    }

    private function rgb(): DeviceColourSpace
    {
        return new DeviceColourSpace(ColourSpaceFamily::DeviceRGB);
    }

    private function colour(DeviceColourSpace $space, int|float ...$numbers): ColourComponents
    {
        return new ColourComponents($space, ...array_map(static fn (int|float $value): NumericType => new NumericType($value), $numbers));
    }

    private function graySamples(): ImageSamples
    {
        return new ImageSamples(new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::Gray), "\0");
    }

    private function box(): Rectangle
    {
        return new Rectangle(new NumericType(0), new NumericType(0), new NumericType(1), new NumericType(1));
    }

    private function function(): ExponentialInterpolationFunction
    {
        return new ExponentialInterpolationFunction(new Domain(new NumberInterval(new NumericType(0), new NumericType(1))), new NumericType(1), new NumberArray(new NumericType(0)), new NumberArray(new NumericType(1)));
    }

    private function file(StreamObject $image, string $version, ?string $catalogVersion = null): FileStructure
    {
        $resources = new ResourceDictionary(new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Image'), new IndirectReference($image)))));
        $page = new PageObject($this->box(), $resources, new StreamObject(new ContentStreams(new InvokeXObject(new NameType('Image')))));
        $pages = (new PageTreeNode())->addKid($page);
        $catalog = new DocumentCatalog($pages, ...($catalogVersion === null ? [] : [new Entry(new NameType('Version'), new NameType($catalogVersion))]));
        return new FileStructure(new FileHeader($version), new FileBody($catalog), new IndirectReference($catalog));
    }
}
