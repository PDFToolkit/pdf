<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\NumberInterval;
use PDF\Functions\Range;
use PDF\Specification\Dictionary\ICCProfileStreamDictionaryTable66;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/** PDF profile embedding (table 66, pages 157–159); ICC payload validation belongs to the profile supplier. */
final class ICCProfile extends StreamObject implements \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF13, 'ICC profile stream');
    }

    public readonly Range $componentRanges;

    public function __construct(
        string $profileBytes,
        public readonly IntegerType $components,
        public readonly ?ColourSpace $alternate = null,
        ?Range $ranges = null,
        ?StreamObject $metadata = null,
        Filter ...$filters,
    ) {
        $count = $components->integer();
        if (!in_array($count, [1, 3, 4], true) || $profileBytes === '') {
            throw new \InvalidArgumentException('An ICC profile needs opaque profile bytes and N equal to 1, 3 or 4.');
        }
        if ($alternate !== null && ($alternate->family() === ColourSpaceFamily::Pattern || $alternate->componentCount() !== $count)) {
            throw new \InvalidArgumentException('ICC Alternate must have exactly N components and cannot be Pattern.');
        }
        $this->componentRanges = $ranges ?? new Range(...array_fill(0, $count, new NumberInterval(new NumericType(0), new NumericType(1))));
        if ($this->componentRanges->dimensions() !== $count) {
            throw new \InvalidArgumentException('An ICC Range must have one interval per declared component.');
        }
        $entries = [new Entry(new NameType('N'), $components), new Entry(new NameType('Range'), $this->componentRanges)];
        if ($alternate !== null) {
            $entries[] = new Entry(new NameType('Alternate'), $alternate->value());
        }
        if ($metadata !== null) {
            $entries[] = new Entry(new NameType('Metadata'), new IndirectReference($metadata));
        }
        parent::__construct($profileBytes, new ICCProfileStreamDictionaryTable66(...$entries), ...$filters);
    }

    public function effectiveAlternate(): ColourSpace
    {
        return $this->alternate ?? new DeviceColourSpace(match ($this->components->integer()) {
            1 => ColourSpaceFamily::DeviceGray,
            3 => ColourSpaceFamily::DeviceRGB,
            default => ColourSpaceFamily::DeviceCMYK,
        });
    }
}
