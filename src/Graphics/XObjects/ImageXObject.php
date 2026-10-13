<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Document\Images\ImageSamples;
use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Graphics\ColourSpaces\Indexed;
use PDF\Graphics\Patterns\Shadings\DecodeInterval;
use PDF\Specification\Definition\ImageDictionaryTable89Definition;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/** Real image XObject from decoded normalized rows; masks and Decode are explicit. */
final class ImageXObject extends StreamObject implements \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        if ($this->samples->layout->bitsPerComponent === 16) {
            $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF15, 'Sixteen-bit image samples');
        }
    }

    public readonly ColourSpace $colourSpace;
    public readonly ImageDecode $decode;

    public function __construct(public readonly ImageSamples $samples, ?ImageDecode $decode = null, public readonly ImageMask|ColourKeyMask|null $mask = null, public readonly ?SoftMaskImage $softMask = null, ImageOptions $options = new ImageOptions(), Filter ...$filters)
    {
        $this->colourSpace = $samples->layout->space();
        if ($decode === null) {
            $intervals = [];
            if ($this->colourSpace instanceof Indexed) {
                $intervals[] = new DecodeInterval(new NumericType(0), new NumericType((1 << $samples->layout->bitsPerComponent) - 1));
            } else {
                $ranges = $this->colourSpace->ranges();
                if ($ranges === null) {
                    throw new \InvalidArgumentException('Image Decode defaults require every colour component range.');
                }
                foreach ($ranges->intervals as $interval) {
                    $intervals[] = new DecodeInterval($interval->minimum, $interval->maximum);
                }
            }
            $decode = new ImageDecode(...$intervals);
        }
        if (count($decode->intervals) !== $samples->layout->components()) {
            throw new \InvalidArgumentException('Image Decode requires one endpoint pair per sample component.');
        }
        $this->decode = $decode;
        if ($mask instanceof ColourKeyMask) {
            $mask->validate($samples->layout->components(), $samples->layout->bitsPerComponent);
        }
        $softMask?->validateParent($samples, $this->colourSpace);
        foreach ($filters as $filter) {
            if ($filter instanceof EncodedData || ($filter->name()->value === 'RunLengthDecode' && $samples->layout->bitsPerComponent !== 8)) {
                throw new \InvalidArgumentException('Decoded image rows require compatible PDF filters, with eight-bit samples for RunLengthDecode.');
            }
        }
        $entries = [new Entry(new NameType('Type'), new NameType('XObject')), new Entry(new NameType('Subtype'), new NameType('Image')), new Entry(new NameType('Width'), new IntegerType($samples->dimensions->width)), new Entry(new NameType('Height'), new IntegerType($samples->dimensions->height)), new Entry(new NameType('ColorSpace'), $this->colourSpace->value()), new Entry(new NameType('BitsPerComponent'), new IntegerType($samples->layout->bitsPerComponent)), new Entry(new NameType('Decode'), $decode), ...$options->entries()];
        if ($mask !== null) {
            $entries[] = new Entry(new NameType('Mask'), $mask instanceof ImageMask ? new IndirectReference($mask) : $mask);
        }
        if ($softMask !== null) {
            $entries[] = new Entry(new NameType('SMask'), new IndirectReference($softMask));
        }
        parent::__construct($samples->bytes, new XObjectDictionary(new ImageDictionaryTable89Definition(), ...$entries), ...$filters);
    }

    public function colourAt(int $x, int $y): ColourComponents
    {
        return $this->decodedAt($x, $y)->clipped();
    }

    /** Exposes the linear Decode result before PDF colour-space clipping. */
    public function decodedAt(int $x, int $y): ColourComponents
    {
        $numbers = [];
        foreach ($this->decode->intervals as $index => $interval) {
            $numbers[] = $interval->decode($this->samples->sample($x, $y, $index), $this->samples->layout->bitsPerComponent);
        }
        return new ColourComponents($this->colourSpace, ...$numbers);
    }
}
