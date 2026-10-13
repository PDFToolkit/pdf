<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\Range;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NameType;

/** Shared explicit array representation of calibrated and special PDF colour spaces. */
abstract class ArrayColourSpace extends ArrayType implements ColourSpace, \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $minimum = match ($this->family()) {
            ColourSpaceFamily::Pattern, ColourSpaceFamily::Separation => \PDF\Syntax\FileStructure\PdfVersion::PDF12,
            ColourSpaceFamily::ICCBased, ColourSpaceFamily::DeviceN => \PDF\Syntax\FileStructure\PdfVersion::PDF13,
            default => \PDF\Syntax\FileStructure\PdfVersion::PDF11,
        };
        $version->require($minimum, $this->family()->value . ' colour space');
    }

    protected function __construct(private readonly ColourSpaceFamily $space, private readonly ?Range $componentRanges, BasicType ...$parameters)
    {
        parent::__construct(new NameType($space->value), ...$parameters);
    }

    public function value(): ArrayType
    {
        return $this;
    }

    public function family(): ColourSpaceFamily
    {
        return $this->space;
    }

    public function componentCount(): int
    {
        return $this->componentRanges?->dimensions() ?? 0;
    }

    public function ranges(): ?Range
    {
        return $this->componentRanges;
    }
}
