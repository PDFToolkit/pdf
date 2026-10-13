<?php

declare(strict_types=1);

namespace PDF\Document\Images;

use PDF\Specification\Dictionary\DCTDecodeFilterTable13;
use PDF\Specification\Dictionary\DCTDecodeFilterTable13\ColorTransformEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89;
use PDF\Specification\Dictionary\ImageDictionaryTable89\BitsPerComponentEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\ColorSpaceEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\DecodeEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\HeightEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\SMaskEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\SubtypeEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\TypeEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\WidthEntry;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\FilterName;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Composes table 89 image streams; painting and resource naming remain explicit. */
final readonly class ImageXObjectFactory
{
    public function __construct(private FlateDecode $sampleCompression = new FlateDecode())
    {
    }

    public function create(SampledImage|DCTImage $image, Entry ...$entries): StreamObject
    {
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, [
                'Type', 'Subtype', 'Width', 'Height', 'ColorSpace', 'BitsPerComponent',
                'Decode', 'SMask', 'Mask', 'ImageMask', 'SMaskInData',
            ], true)) {
                throw new \InvalidArgumentException('Image composition manages /' . $entry->key->value . '.');
            }
        }
        $dimensions = $image instanceof SampledImage ? $image->samples->dimensions : $image->dimensions;
        $layout = $image instanceof SampledImage ? $image->samples->layout : $image->layout;
        $dictionaryEntries = [
            new TypeEntry(new NameType('XObject')),
            new SubtypeEntry(new NameType('Image')),
            new WidthEntry(new IntegerType($dimensions->width)),
            new HeightEntry(new IntegerType($dimensions->height)),
            new ColorSpaceEntry(new NameType($layout->colorSpace->value)),
            new BitsPerComponentEntry(new IntegerType($layout->bitsPerComponent)),
        ];
        if ($image instanceof SampledImage && $image->alpha !== null) {
            $mask = $this->create(new SampledImage($image->alpha));
            $dictionaryEntries[] = new SMaskEntry(new IndirectReference($mask));
        }
        if ($image instanceof DCTImage && $image->invertedComponents) {
            $dictionaryEntries[] = new DecodeEntry(new ArrayType(
                new IntegerType(1),
                new IntegerType(0),
                new IntegerType(1),
                new IntegerType(0),
                new IntegerType(1),
                new IntegerType(0),
                new IntegerType(1),
                new IntegerType(0),
            ));
        }
        $dictionary = new ImageDictionaryTable89(...$dictionaryEntries, ...$entries);
        if ($image instanceof SampledImage) {
            return new StreamObject($image->samples->bytes, $dictionary, $this->sampleCompression);
        }
        return new StreamObject($image->encodedBytes, $dictionary, new EncodedData(
            FilterName::DCTDecode,
            new DCTDecodeFilterTable13(new ColorTransformEntry(new IntegerType($image->colorTransform))),
        ));
    }
}
