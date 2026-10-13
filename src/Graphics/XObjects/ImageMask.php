<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Document\Images\ImageSamples;
use PDF\Specification\Definition\ImageDictionaryTable89Definition;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** One-bit stencil image; absent ColorSpace/Mask/SMask is enforced (8.9.6.2). */
final class ImageMask extends StreamObject
{
    public function __construct(public readonly ImageSamples $samples, public readonly BooleanType $inverted = new BooleanType(false), ImageOptions $options = new ImageOptions(), Filter ...$filters)
    {
        if ($samples->layout->bitsPerComponent !== 1 || $samples->layout->components() !== 1) {
            throw new \InvalidArgumentException('A stencil image requires exactly one one-bit sample per pixel.');
        }
        foreach ($filters as $filter) {
            if ($filter instanceof EncodedData) {
                throw new \InvalidArgumentException('Decoded stencil samples cannot be passed off as an external encoded codec payload.');
            }
        }
        parent::__construct($samples->bytes, new XObjectDictionary(new ImageDictionaryTable89Definition(), new Entry(new NameType('Type'), new NameType('XObject')), new Entry(new NameType('Subtype'), new NameType('Image')), new Entry(new NameType('Width'), new IntegerType($samples->dimensions->width)), new Entry(new NameType('Height'), new IntegerType($samples->dimensions->height)), new Entry(new NameType('ImageMask'), new BooleanType(true)), new Entry(new NameType('BitsPerComponent'), new IntegerType(1)), new Entry(new NameType('Decode'), new ArrayType(new IntegerType($inverted->value ? 1 : 0), new IntegerType($inverted->value ? 0 : 1))), ...$options->entries()), ...$filters);
    }

    public function paints(int $x, int $y): bool
    {
        return $this->samples->sample($x, $y, 0) === ($this->inverted->value ? 1 : 0);
    }
}
