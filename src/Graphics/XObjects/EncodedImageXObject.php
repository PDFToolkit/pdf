<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Document\Images\ImageDimensions;
use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Specification\Definition\ImageDictionaryTable89Definition;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Filters\FilterName;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Externally encoded samples with explicit PDF metadata; no image codec is parsed. */
final class EncodedImageXObject extends StreamObject implements \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        foreach ($this->filters as $filter) {
            if ($filter->name()->value === 'JPXDecode') {
                $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF15, 'JPEG2000 image');
            }
            if ($filter->name()->value === 'JBIG2Decode') {
                $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF14, 'JBIG2 image');
            }
        }
        if ($this->bitsPerComponent?->integer() === 16) {
            $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF15, 'Sixteen-bit image samples');
        }
    }

    public function __construct(string $bytes, public readonly ImageDimensions $dimensions, EncodedData $encoded, public readonly ?ColourSpace $colourSpace = null, public readonly ?IntegerType $bitsPerComponent = null, ?ImageDecode $decode = null, ImageMask|ColourKeyMask|null $mask = null, ?SoftMaskImage $softMask = null, EmbeddedImageAlpha $embeddedAlpha = EmbeddedImageAlpha::Ignore, ImageOptions $options = new ImageOptions(), Filter ...$outerFilters)
    {
        $jpx = $encoded->filter === FilterName::JPXDecode;
        if ($bytes === '' || (!$jpx && ($colourSpace === null || $bitsPerComponent === null)) || $colourSpace?->family() === ColourSpaceFamily::Pattern) {
            throw new \InvalidArgumentException('Encoded images need nonempty bytes and explicit colour/depth metadata except for JPX.');
        }
        $bits = $bitsPerComponent?->integer();
        if ($bits !== null && !in_array($bits, [1, 2, 4, 8, 16], true)) {
            throw new \InvalidArgumentException('Image BitsPerComponent must be 1, 2, 4, 8 or 16.');
        }
        if ((in_array($encoded->filter, [FilterName::DCTDecode, FilterName::RunLengthDecode], true) && $bits !== 8) || (in_array($encoded->filter, [FilterName::CCITTFaxDecode, FilterName::JBIG2Decode], true) && $bits !== 1)) {
            throw new \InvalidArgumentException('The declared image sample depth must match its external PDF codec contract.');
        }
        if (($embeddedAlpha !== EmbeddedImageAlpha::Ignore && !$jpx) || ($embeddedAlpha !== EmbeddedImageAlpha::Ignore && $softMask !== null)) {
            throw new \InvalidArgumentException('Embedded alpha is JPX-only and cannot coexist with SMask.');
        }
        if ($decode !== null && ($colourSpace === null || count($decode->intervals) !== $colourSpace->componentCount())) {
            throw new \InvalidArgumentException('Explicit Decode requires a known colour space and one pair per component.');
        }
        if ($mask instanceof ColourKeyMask) {
            if ($colourSpace === null || $bits === null) {
                throw new \InvalidArgumentException('Colour-key masking needs explicitly declared raw component count and depth.');
            }
            $mask->validate($colourSpace->componentCount(), $bits);
        }
        if ($softMask !== null && $softMask->matte !== null) {
            if ($colourSpace === null) {
                throw new \InvalidArgumentException('Matte validation needs an explicit parent colour space.');
            }
            $softMask->validateParentDimensions($dimensions, $colourSpace);
        }
        foreach ($outerFilters as $filter) {
            if ($filter instanceof EncodedData) {
                throw new \InvalidArgumentException('Only one external encoded stage may terminate an image filter chain.');
            }
        }
        $entries = [new Entry(new NameType('Type'), new NameType('XObject')), new Entry(new NameType('Subtype'), new NameType('Image')), new Entry(new NameType('Width'), new IntegerType($dimensions->width)), new Entry(new NameType('Height'), new IntegerType($dimensions->height)), ...$options->entries()];
        foreach (['ColorSpace' => $colourSpace?->value(), 'BitsPerComponent' => $bitsPerComponent, 'Decode' => $decode, 'Mask' => $mask instanceof ImageMask ? new IndirectReference($mask) : $mask, 'SMask' => $softMask === null ? null : new IndirectReference($softMask), 'SMaskInData' => $jpx ? new IntegerType($embeddedAlpha->value) : null] as $name => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($name), $value);
            }
        }
        parent::__construct($bytes, new XObjectDictionary(new ImageDictionaryTable89Definition(), ...$entries), ...[...$outerFilters, $encoded]);
    }
}
