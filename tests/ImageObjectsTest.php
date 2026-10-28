<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Images\DCTImage;
use PDF\Document\Images\DeviceColorSpace;
use PDF\Document\Images\ImageDimensions;
use PDF\Document\Images\ImageSamples;
use PDF\Document\Images\ImageXObjectFactory;
use PDF\Document\Images\SampledImage;
use PDF\Document\Images\SampleLayout;
use PDF\Graphics\GraphicsState\RenderingIntent;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageObjectsTest extends TestCase
{
    public function testImportedRenderingIntentCanBeExplicitlyOverriddenWithoutChangingSamples(): void
    {
        $samples = new ImageSamples(new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::RGB), "\xff\0\0");
        $alpha = new ImageSamples($samples->dimensions, new SampleLayout(DeviceColorSpace::Gray), "\x80");
        $factory = new ImageXObjectFactory();
        $image = new SampledImage($samples, $alpha, RenderingIntent::Perceptual);
        $imported = $factory->create($image);
        self::assertEquals(new NameType('Perceptual'), $imported->dictionary()->get('Intent'));
        $override = $factory->create($image, new Entry(new NameType('Intent'), new NameType('RelativeColorimetric')));
        self::assertEquals(new NameType('RelativeColorimetric'), $override->dictionary()->get('Intent'));
        self::assertSame(1, substr_count($override->toPdf(new SerializationContext()), '/Intent '));
        self::assertSame($samples->bytes, gzuncompress($override->bytes(new SerializationContext())));
        $reference = $imported->dictionary()->get('SMask');
        self::assertInstanceOf(IndirectReference::class, $reference);
        $mask = $reference->target();
        self::assertInstanceOf(StreamObject::class, $mask);
        self::assertFalse($mask->dictionary()->has('Intent'));
        self::assertSame($alpha->bytes, gzuncompress($mask->bytes(new SerializationContext())));
        self::assertFalse($factory->create(new SampledImage($samples))->dictionary()->has('Intent'));
    }

    public function testRawSamplesAndMaskProduceIndependentPdfImageStreams(): void
    {
        $dimensions = new ImageDimensions(2, 1);
        $image = new SampledImage(
            new ImageSamples($dimensions, new SampleLayout(DeviceColorSpace::RGB), "\xff\0\0\0\xff\0"),
            new ImageSamples($dimensions, new SampleLayout(DeviceColorSpace::Gray), "\0\xff"),
        );
        $stream = (new ImageXObjectFactory())->create($image);
        self::assertEquals(new NameType('Image'), $stream->dictionary()->get('Subtype'));
        self::assertEquals(new IntegerType(2), $stream->dictionary()->get('Width'));
        self::assertEquals(new IntegerType(1), $stream->dictionary()->get('Height'));
        self::assertEquals(new IntegerType(8), $stream->dictionary()->get('BitsPerComponent'));
        self::assertEquals(new NameType('DeviceRGB'), $stream->dictionary()->get('ColorSpace'));
        self::assertSame($image->samples->bytes, gzuncompress($stream->bytes(new SerializationContext())));
        $reference = $stream->dictionary()->get('SMask');
        self::assertInstanceOf(IndirectReference::class, $reference);
        $mask = $reference->target();
        self::assertInstanceOf(StreamObject::class, $mask);
        self::assertEquals(new NameType('DeviceGray'), $mask->dictionary()->get('ColorSpace'));
        self::assertFalse($mask->dictionary()->has('SMask'));
        self::assertSame($image->alpha?->bytes, gzuncompress($mask->bytes(new SerializationContext())));
        $context = new SerializationContext();
        $context->prepare($stream);
        self::assertCount(2, $context->objects());
        self::assertStringContainsString('/SMask ', $stream->toPdf($context));
    }

    public function testDctEncodingRemainsExplicitAndItsBytesArePreserved(): void
    {
        $image = new DCTImage('opaque DCT-encoded bytes', new ImageDimensions(2, 1), new SampleLayout(DeviceColorSpace::RGB), 1);
        $stream = (new ImageXObjectFactory())->create($image);
        self::assertSame($image->encodedBytes, $stream->bytes(new SerializationContext()));
        self::assertSame('DCTDecode', $stream->filters[0]->name()->value);
        self::assertEquals(new IntegerType(1), $stream->filters[0]->parameters()?->get('ColorTransform'));
        self::assertFalse($stream->dictionary()->has('Decode'));
        self::assertFalse($stream->dictionary()->has('SMask'));
        self::assertStringContainsString('/DecodeParms << /ColorTransform 1 >>', $stream->toPdf(new SerializationContext()));
    }

    public function testInvertedCmykComponentsSetExplicitDecodeRanges(): void
    {
        $image = new DCTImage('opaque DCT-encoded bytes', new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::CMYK), 0, true);
        $stream = (new ImageXObjectFactory())->create($image);
        self::assertEquals(new NameType('DeviceCMYK'), $stream->dictionary()->get('ColorSpace'));
        self::assertStringContainsString('/Decode [1 0 1 0 1 0 1 0]', $stream->toPdf(new SerializationContext()));
        self::assertEquals(new IntegerType(0), $stream->filters[0]->parameters()?->get('ColorTransform'));
    }

    public function testInvertedIccCmykPreservesItsProfileAndDecodeRanges(): void
    {
        $profile = new \PDF\Graphics\ColourSpaces\ICCProfile('opaque CMYK profile bytes', new IntegerType(4));
        $space = new \PDF\Graphics\ColourSpaces\ICCBased($profile);
        $image = new DCTImage('opaque YCCK bytes', new ImageDimensions(1, 1), new SampleLayout($space), 1, true);
        $stream = (new ImageXObjectFactory())->create($image);
        $context = new SerializationContext();
        $context->prepare($stream);
        self::assertSame($space, $image->layout->space());
        self::assertSame('opaque YCCK bytes', $stream->bytes($context));
        self::assertStringContainsString('/Decode [1 0 1 0 1 0 1 0]', $stream->toPdf($context));
        self::assertStringContainsString('/ColorSpace [/ICCBased ', $stream->toPdf($context));
        self::assertCount(2, $context->objects());
        self::assertEquals(new IntegerType(1), $stream->filters[0]->parameters()?->get('ColorTransform'));
    }

    /** @return iterable<string, array{string}> */
    public static function managedEntries(): iterable
    {
        foreach (['Type', 'Subtype', 'Width', 'Height', 'ColorSpace', 'BitsPerComponent', 'Decode', 'SMask', 'Mask', 'ImageMask', 'SMaskInData'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('managedEntries')]
    public function testImageConstructorManagedEntriesCannotBeOverridden(string $key): void
    {
        $image = new SampledImage(new ImageSamples(new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::Gray), "\xff"));
        $this->expectException(\InvalidArgumentException::class);
        (new ImageXObjectFactory())->create($image, new Entry(new NameType($key), new IntegerType(99)));
    }

    public function testDimensionsAndSampleLengthAreValidated(): void
    {
        foreach ([[0, 1], [1, 0], [-1, 2], [PHP_INT_MAX, 2]] as [$width, $height]) {
            try {
                new ImageDimensions($width, $height);
                self::fail('Expected invalid dimensions rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new ImageSamples(new ImageDimensions(2, 2), new SampleLayout(DeviceColorSpace::RGB), 'short');
    }

    public function testComponentCountOverflowIsRejected(): void
    {
        $dimensions = new ImageDimensions(PHP_INT_MAX, 1);
        $this->expectException(\InvalidArgumentException::class);
        $dimensions->sampleBytes(new SampleLayout(DeviceColorSpace::RGB));
    }

    public function testUnsupportedSampleDepthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SampleLayout(DeviceColorSpace::RGB, 3);
    }

    public function testAlphaDimensionsAndColorSpaceAreValidated(): void
    {
        $samples = new ImageSamples(new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::RGB), "\0\0\0");
        foreach ([
            new ImageSamples(new ImageDimensions(2, 1), new SampleLayout(DeviceColorSpace::Gray), "\0\0"),
            new ImageSamples(new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::RGB), "\0\0\0"),
        ] as $alpha) {
            try {
                new SampledImage($samples, $alpha);
                self::fail('Expected invalid alpha samples rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testDctDataTransformAndComponentInversionAreValidated(): void
    {
        foreach ([['', 0, false], ['encoded', -1, false], ['encoded', 2, false], ['encoded', 0, true]] as [$bytes, $transform, $inverted]) {
            try {
                new DCTImage($bytes, new ImageDimensions(1, 1), new SampleLayout(DeviceColorSpace::RGB), $transform, $inverted);
                self::fail('Expected invalid DCT image rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }
}
