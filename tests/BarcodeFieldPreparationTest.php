<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Barcodes\BarcodeDataPreparation;
use PDF\Document\Barcodes\BarcodeEncodingRequest;
use PDF\Document\Barcodes\BarcodeFieldPreparation;
use PDF\Document\Barcodes\BarcodeImageEncoder;
use PDF\Document\Barcodes\BarcodeLimits;
use PDF\Document\Barcodes\BarcodeWidgetAppearance;
use PDF\Document\Barcodes\BarcodeWidgetParameters;
use PDF\Document\Forms\BarcodeField;
use PDF\Document\Forms\BarcodePaperMetadata;
use PDF\Document\Forms\BarcodeSymbology;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\NonTerminalField;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Images\DeviceColorSpace;
use PDF\Document\Images\ImageDimensions;
use PDF\Document\Images\ImageSamples;
use PDF\Document\Images\SampleLayout;
use PDF\Graphics\XObjects\ImageOptions;
use PDF\Graphics\XObjects\ImageXObject;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\Utf16BETextStringEncoding;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\TestCase;

final class BarcodeFieldPreparationTest extends TestCase
{
    private function field(string $text = 'value', int $preparation = 0): BarcodeField
    {
        return new BarcodeField(new PartialFieldName(new TextString('barcode')), new BarcodeDataPreparation(new IntegerType($preparation)), new TextString($text));
    }

    private function parameters(int $width = 100, int $height = 50, bool $reversed = false, BarcodeSymbology $symbology = BarcodeSymbology::QrCode): BarcodeWidgetParameters
    {
        $rectangle = new Rectangle(new NumericType(10), new NumericType(20), new NumericType(10 + $width), new NumericType(20 + $height));
        if ($reversed) {
            $rectangle = new Rectangle($rectangle->upperRightX, $rectangle->upperRightY, $rectangle->lowerLeftX, $rectangle->lowerLeftY);
        }
        return new BarcodeWidgetParameters($rectangle, new BarcodePaperMetadata($symbology, new NumericType(1), new NumericType(1), new IntegerType(1), errorCorrection: $symbology === BarcodeSymbology::QrCode ? new IntegerType(0) : null));
    }

    private function image(): ImageXObject
    {
        return new ImageXObject(new ImageSamples(new ImageDimensions(2, 2), new SampleLayout(DeviceColorSpace::Gray), "\0\xff\xff\0"));
    }

    private function encoder(ImageXObject $image): RecordingBarcodeImageEncoder
    {
        return new RecordingBarcodeImageEncoder($image);
    }

    public function testFreshPlanReadsExactStoredValueAndBindsPartsToActualOrderedPmd(): void
    {
        $field = $this->field('abcdefgh');
        $first = $this->parameters(symbology: BarcodeSymbology::DataMatrix);
        $second = $this->parameters();
        $plan = new BarcodeFieldPreparation($field, new BarcodeLimits(), $first, $second);
        self::assertSame($field->get('V'), $plan->partition->value);
        self::assertSame('abcd', $plan->requests[0]->message->value->value);
        self::assertSame('efgh', $plan->requests[1]->message->value->value);
        self::assertSame($first, $plan->requests[0]->widget);
        self::assertSame($second, $plan->requests[1]->widget);
        self::assertSame([], $field->widgets());
        $encoder = $this->encoder($this->image());
        $rendered = $plan->render($encoder);
        self::assertSame($plan->requests, $encoder->requests);
        foreach ($rendered as $index => $rendering) {
            self::assertSame($plan->requests[$index], $rendering->request);
            self::assertSame($plan->requests[$index]->widget->metadata, $rendering->widget->get('PMD'));
            self::assertSame($rendering->appearance, $rendering->widget->get('AP'));
        }
        self::assertSame([], $field->widgets(), 'Rendering never silently attaches or edits a field.');
        foreach ($rendered as $rendering) {
            $field->addWidget($rendering->widget);
        }
        $kids = $field->get('Kids');
        self::assertCount(2, $kids->items);
        self::assertSame($rendered[0]->widget, $kids->items[0]->target());
        self::assertSame($rendered[1]->widget, $kids->items[1]->target());
    }

    public function testNamedVariadicWidgetArgumentsRetainTheirInsertionOrder(): void
    {
        $first = $this->parameters(80);
        $second = $this->parameters(140);
        $plan = new BarcodeFieldPreparation($this->field('abc'), second: $first, first: $second);
        self::assertSame([$first, $second], array_map(static fn (BarcodeEncodingRequest $request) => $request->widget, $plan->requests));
        self::assertSame(['ab', 'c'], array_map(static fn (BarcodeEncodingRequest $request) => $request->message->value->value, $plan->requests));
    }

    public function testAnExplicitInheritedValueIsUsedFromItsActualFieldAncestor(): void
    {
        $value = new TextString('inherited');
        $field = new BarcodeField(new PartialFieldName(new TextString('child')), new BarcodeDataPreparation());
        (new NonTerminalField(new PartialFieldName(new TextString('parent')), new Entry(new NameType('V'), $value)))->addField($field);
        self::assertNull($field->get('V'));
        $plan = new BarcodeFieldPreparation($field, new BarcodeLimits(), $this->parameters(), $this->parameters());
        self::assertSame($value, $plan->partition->value);
        self::assertSame(['inher', 'ited'], array_map(static fn (BarcodeEncodingRequest $request) => $request->message->value->value, $plan->requests));
        self::assertCount(2, $plan->render($this->encoder($this->image())));
    }

    public function testFieldPlanPreservesStoredUtf16BomThroughGlobalCompression(): void
    {
        $value = new TextString('été', new Utf16BETextStringEncoding());
        $field = new BarcodeField(new PartialFieldName(new TextString('unicode')), new BarcodeDataPreparation(new IntegerType(1)), $value);
        $plan = new BarcodeFieldPreparation($field, new BarcodeLimits(), $this->parameters(), $this->parameters(), $this->parameters());
        $encoder = $this->encoder($this->image());
        $plan->render($encoder);
        $joined = implode('', array_map(static fn (BarcodeEncodingRequest $request) => $request->message->value->value, $encoder->requests));
        self::assertSame($value->value, gzuncompress($joined));
        self::assertStringStartsWith("\xfe\xff", gzuncompress($joined));
        self::assertSame([], $field->widgets());
    }

    public function testNormalAppearanceHasLocalBboxAndPaintsImageAcrossBothCompleteExtents(): void
    {
        foreach ([false, true] as $reversed) {
            $plan = new BarcodeFieldPreparation($this->field(), new BarcodeLimits(), $this->parameters(200, 70, $reversed));
            $image = $this->image();
            $rendered = $plan->render($this->encoder($image))[0];
            self::assertSame('[0 0 200 70]', $rendered->form->dictionary()->get('BBox')->toPdf(new SerializationContext()));
            self::assertSame("q\n200 0 0 70 0 0 cm\n/BarcodeImage Do\nQ\n", $rendered->form->bytes(new SerializationContext()));
            $normal = $rendered->widget->get('AP')->get('N');
            self::assertInstanceOf(IndirectReference::class, $normal);
            self::assertSame($rendered->form, $normal->target());
            $resource = $rendered->form->dictionary()->get('Resources')->get('XObject')->get('BarcodeImage');
            self::assertSame($image, $resource->target());
            self::assertSame($plan->requests[0]->widget->rectangle, $rendered->widget->get('Rect'));
            self::assertFalse($rendered->widget->has('AA'));
            self::assertFalse($rendered->widget->has('A'));
        }
    }

    public function testSingleWidgetCanRemainSeparateOrBeExplicitlyMerged(): void
    {
        foreach ([false, true] as $merged) {
            $field = $this->field();
            $rendered = (new BarcodeFieldPreparation($field, new BarcodeLimits(), $this->parameters()))->render($this->encoder($this->image()))[0];
            if ($merged) {
                $field->mergeWidget($rendered->widget);
                self::assertFalse($field->has('Kids'));
                self::assertSame($rendered->appearance, $field->get('AP'));
                self::assertSame($rendered->request->widget->metadata, $field->get('PMD'));
            } else {
                $field->addWidget($rendered->widget);
                self::assertCount(1, $field->get('Kids')->items);
                self::assertFalse($field->has('AP'));
            }
        }
    }

    public function testMissingValueRequiresAnExplicitDecisionInsteadOfAssumingEmptyText(): void
    {
        $field = new BarcodeField(new PartialFieldName(new TextString('absent')), new BarcodeDataPreparation());
        $this->expectException(\InvalidArgumentException::class);
        new BarcodeFieldPreparation($field, new BarcodeLimits(), $this->parameters());
    }

    public function testNoWidgetPlanFailsWithoutAnEncoderCall(): void
    {
        $this->expectException(\LengthException::class);
        new BarcodeFieldPreparation($this->field());
    }

    public function testFreshPlanCannotSilentlyReplaceExistingWidgets(): void
    {
        $field = $this->field();
        $field->addWidget(new WidgetAnnotation($this->parameters()->rectangle));
        $this->expectException(\LogicException::class);
        new BarcodeFieldPreparation($field, new BarcodeLimits(), $this->parameters());
    }

    public function testAPlanCannotRenderAfterItsFieldAcquiredWidgets(): void
    {
        $field = $this->field();
        $plan = new BarcodeFieldPreparation($field, new BarcodeLimits(), $this->parameters());
        $encoder = $this->encoder($this->image());
        $field->addWidget(new WidgetAnnotation($this->parameters()->rectangle));
        try {
            $plan->render($encoder);
            self::fail('A stale plan rendered its original assumptions.');
        } catch (\LogicException) {
            self::assertSame([], $encoder->requests);
        }
    }

    public function testByteAndWidgetBudgetsApplyBeforeExternalEncoding(): void
    {
        foreach ([new BarcodeLimits(maximumInputBytes: 2), new BarcodeLimits(maximumPreparedBytes: 2), new BarcodeLimits(maximumWidgets: 1)] as $limits) {
            try {
                new BarcodeFieldPreparation($this->field('abc'), $limits, $this->parameters(), $this->parameters());
                self::fail('An over-budget plan was constructed.');
            } catch (\LengthException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReturnedImagePixelBudgetIsCheckedBeforeConstructingAppearances(): void
    {
        $field = $this->field();
        $plan = new BarcodeFieldPreparation($field, new BarcodeLimits(maximumImagePixels: 3), $this->parameters());
        $encoder = $this->encoder($this->image());
        try {
            $plan->render($encoder);
            self::fail('An oversized generated image was accepted.');
        } catch (\LengthException) {
            self::assertCount(1, $encoder->requests);
            self::assertSame([], $field->widgets());
        }
    }

    public function testCumulativeImageBudgetDoesNotResetForEveryWidget(): void
    {
        foreach ([new BarcodeLimits(maximumImagePixels: 6), new BarcodeLimits(maximumImageBytes: 6)] as $limits) {
            $field = $this->field();
            $encoder = $this->encoder($this->image());
            $plan = new BarcodeFieldPreparation($field, $limits, $this->parameters(), $this->parameters());
            try {
                $plan->render($encoder);
                self::fail('The image budget was applied separately to each widget.');
            } catch (\LengthException) {
                self::assertCount(2, $encoder->requests);
                self::assertSame([], $field->widgets());
            }
        }
    }

    public function testExhaustionStopsBeforeTheNextExternalEncoderCall(): void
    {
        $encoder = $this->encoder($this->image());
        $plan = new BarcodeFieldPreparation($this->field(), new BarcodeLimits(maximumImagePixels: 4), $this->parameters(), $this->parameters());
        try {
            $plan->render($encoder);
            self::fail('Encoding continued after the global pixel budget was exhausted.');
        } catch (\LengthException) {
            self::assertCount(1, $encoder->requests);
        }
    }

    public function testOptionalImageResourceGraphsAreOutsideTheNormalizedEncoderContract(): void
    {
        $image = new ImageXObject($this->image()->samples, options: new ImageOptions(metadata: new StreamObject('<external/>')));
        $encoder = $this->encoder($image);
        $this->expectException(\InvalidArgumentException::class);
        (new BarcodeFieldPreparation($this->field(), new BarcodeLimits(), $this->parameters()))->render($encoder);
    }

    public function testAnEncoderFailureNeverAttachesPartiallyPreparedWidgets(): void
    {
        $field = $this->field();
        $encoder = new class () implements BarcodeImageEncoder {
            public function encode(BarcodeEncodingRequest $request): ImageXObject
            {
                throw new \RuntimeException('The selected symbology cannot encode this message.');
            }
        };
        try {
            (new BarcodeFieldPreparation($field, new BarcodeLimits(), $this->parameters()))->render($encoder);
            self::fail('External encoding failure was replaced by a fabricated symbol.');
        } catch (\RuntimeException $error) {
            self::assertSame('The selected symbology cannot encode this message.', $error->getMessage());
            self::assertSame([], $field->widgets());
        }
    }

    public function testTheWidgetCannotReplaceThePmdThatWasSubmittedToEncoding(): void
    {
        $request = (new BarcodeFieldPreparation($this->field(), new BarcodeLimits(), $this->parameters()))->requests[0];
        $this->expectException(\InvalidArgumentException::class);
        new BarcodeWidgetAppearance($request, $this->image(), new Entry(new NameType('PMD'), $this->parameters()->metadata));
    }
}

/** Deliberately returns a test pattern, not a PDF417/QR/Data Matrix implementation. */
final class RecordingBarcodeImageEncoder implements BarcodeImageEncoder
{
    /** @var list<BarcodeEncodingRequest> */
    public array $requests = [];

    public function __construct(private readonly ImageXObject $image)
    {
    }

    public function encode(BarcodeEncodingRequest $request): ImageXObject
    {
        $this->requests[] = $request;
        return $this->image;
    }
}
