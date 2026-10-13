<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\NumberInterval;
use PDF\Functions\Range;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** DeviceGray/DeviceRGB/DeviceCMYK remain PDF names, not fictitious array objects (pages 150–151). */
final readonly class DeviceColourSpace implements ColourSpace
{
    public function __construct(private ColourSpaceFamily $space)
    {
        if (!in_array($space, [ColourSpaceFamily::DeviceGray, ColourSpaceFamily::DeviceRGB, ColourSpaceFamily::DeviceCMYK], true)) {
            throw new \InvalidArgumentException('A device colour space must be DeviceGray, DeviceRGB or DeviceCMYK.');
        }
    }

    public function value(): NameType
    {
        return new NameType($this->space->value);
    }

    public function family(): ColourSpaceFamily
    {
        return $this->space;
    }

    public function componentCount(): int
    {
        return match ($this->space) {
            ColourSpaceFamily::DeviceGray => 1,
            ColourSpaceFamily::DeviceRGB => 3,
            default => 4,
        };
    }

    public function ranges(): Range
    {
        return new Range(...array_fill(0, $this->componentCount(), new NumberInterval(new NumericType(0), new NumericType(1))));
    }
}
