<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Document\Images\ImageSamples;
use PDF\Document\Images\ImageDimensions;
use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Graphics\ColourSpaces\DeviceColourSpace;
use PDF\Specification\Definition\SoftMaskImageDictionaryTable146Definition;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\NumericType;

/** DeviceGray soft-mask image and conditional parent Matte relation (tables 145/146). */
final class SoftMaskImage extends StreamObject implements \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF14, 'Soft-mask image');
        if ($this->samples->layout->bitsPerComponent === 16) {
            $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF15, 'Sixteen-bit soft-mask samples');
        }
    }

    public readonly ImageDecode $decode;
    public function __construct(public readonly ImageSamples $samples, public readonly ?MatteColour $matte = null, ?ImageDecode $decode = null, ImageOptions $options = new ImageOptions(), Filter ...$filters)
    {
        if ($samples->layout->space()->family() !== ColourSpaceFamily::DeviceGray) {
            throw new \InvalidArgumentException('Soft-mask images must use DeviceGray.');
        }
        $image = new ImageXObject($samples, $decode, null, null, $options, ...$filters);
        $this->decode = $image->decode;
        $entries = iterator_to_array($image->dictionary()->entries(), false);
        if ($matte !== null) {
            $entries[] = new Entry(new NameType('Matte'), $matte->colour);
        }
        parent::__construct($samples->bytes, new XObjectDictionary(new SoftMaskImageDictionaryTable146Definition(), ...$entries), ...$filters);
    }

    public function alphaAt(int $x, int $y): NumericType
    {
        $value = $this->decode->intervals[0]->decode($this->samples->sample($x, $y, 0), $this->samples->layout->bitsPerComponent);
        return new NumericType(max(0, min(1, $value->value)));
    }

    public function validateParent(ImageSamples $parent, ColourSpace $space): void
    {
        $this->validateParentDimensions($parent->dimensions, $space);
    }

    public function validateParentDimensions(ImageDimensions $parent, ColourSpace $space): void
    {
        if ($this->matte === null) {
            return;
        }
        $matteSpace = $this->matte->colour->space;
        $sameSpace = $matteSpace === $space || ($matteSpace instanceof DeviceColourSpace && $space instanceof DeviceColourSpace && $matteSpace->family() === $space->family());
        if (!$sameSpace || $this->samples->dimensions->width !== $parent->width || $this->samples->dimensions->height !== $parent->height) {
            throw new \InvalidArgumentException('A Matte soft mask requires matching parent dimensions and parent colour components.');
        }
    }
}
