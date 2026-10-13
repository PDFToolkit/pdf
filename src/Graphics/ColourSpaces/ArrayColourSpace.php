<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\Range;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NameType;

/** Shared explicit array representation of calibrated and special PDF colour spaces. */
abstract class ArrayColourSpace extends ArrayType implements ColourSpace
{
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
