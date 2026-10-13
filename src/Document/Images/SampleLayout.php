<?php

declare(strict_types=1);

namespace PDF\Document\Images;

use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Graphics\ColourSpaces\DeviceColourSpace;

/** Interleaved image components, 1/2/4/8/16 bits each (8.9.5.1). */
final readonly class SampleLayout
{
    public function __construct(public DeviceColorSpace|ColourSpace $colorSpace, public int $bitsPerComponent = 8)
    {
        if (!in_array($bitsPerComponent, [1, 2, 4, 8, 16], true) || ($colorSpace instanceof ColourSpace && $colorSpace->family() === ColourSpaceFamily::Pattern)) {
            throw new \InvalidArgumentException('Images require 1/2/4/8/16-bit components and a non-Pattern colour space.');
        }
    }

    public function components(): int
    {
        return $this->colorSpace instanceof DeviceColorSpace ? $this->colorSpace->components() : $this->colorSpace->componentCount();
    }

    public function space(): ColourSpace
    {
        return $this->colorSpace instanceof DeviceColorSpace ? new DeviceColourSpace(ColourSpaceFamily::from($this->colorSpace->value)) : $this->colorSpace;
    }
}
