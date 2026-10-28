<?php

declare(strict_types=1);

namespace PDF\Document\Images;

use PDF\Graphics\Patterns\Shadings\DecodeInterval;
use PDF\Graphics\XObjects\EncodedImageXObject;
use PDF\Graphics\XObjects\ImageDecode;
use PDF\Graphics\XObjects\ImageOptions;
use PDF\Graphics\XObjects\ImageXObject;
use PDF\Graphics\XObjects\SoftMaskImage;
use PDF\Syntax\Object\NumericType;
use PDF\Specification\Dictionary\DCTDecodeFilterTable13;
use PDF\Specification\Dictionary\DCTDecodeFilterTable13\ColorTransformEntry;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\FilterName;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StreamObject;

/** Converts normalized importer representations to native PDF image objects. */
final readonly class ImageXObjectFactory
{
    public function __construct(private FlateDecode $sampleCompression = new FlateDecode())
    {
    }

    public function create(SampledImage|DCTImage $image, Entry ...$entries): StreamObject
    {
        $intent = $image instanceof SampledImage ? $image->intent : null;
        foreach ($entries as $entry) {
            if ($entry->key->value === 'Intent') {
                $intent = null;
            }
            if (in_array($entry->key->value, [
                'Type', 'Subtype', 'Width', 'Height', 'ColorSpace', 'BitsPerComponent',
                'Decode', 'SMask', 'Mask', 'ImageMask', 'SMaskInData',
            ], true)) {
                throw new \InvalidArgumentException('Image composition manages /' . $entry->key->value . '.');
            }
        }
        $options = new ImageOptions(intent: $intent, extensions: array_values($entries));
        if ($image instanceof SampledImage) {
            $alpha = $image->alpha === null ? null : new SoftMaskImage($image->alpha, null, null, new ImageOptions(), $this->sampleCompression);
            return new ImageXObject($image->samples, null, null, $alpha, $options, $this->sampleCompression);
        }
        $decode = $image->invertedComponents ? new ImageDecode(...array_fill(0, 4, new DecodeInterval(new NumericType(1), new NumericType(0)))) : null;
        return new EncodedImageXObject($image->encodedBytes, $image->dimensions, new EncodedData(
            FilterName::DCTDecode,
            new DCTDecodeFilterTable13(new ColorTransformEntry(new IntegerType($image->colorTransform))),
        ), $image->layout->space(), new IntegerType($image->layout->bitsPerComponent), $decode, options: $options);
    }
}
