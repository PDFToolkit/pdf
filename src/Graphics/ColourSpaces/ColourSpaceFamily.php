<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

/** Colour-space families defined by ISO 32000-1, 8.6.3, PDF page 148. */
enum ColourSpaceFamily: string
{
    case DeviceGray = 'DeviceGray';
    case DeviceRGB = 'DeviceRGB';
    case DeviceCMYK = 'DeviceCMYK';
    case CalGray = 'CalGray';
    case CalRGB = 'CalRGB';
    case Lab = 'Lab';
    case ICCBased = 'ICCBased';
    case Indexed = 'Indexed';
    case Separation = 'Separation';
    case DeviceN = 'DeviceN';
    case Pattern = 'Pattern';

    public function special(): bool
    {
        return in_array($this, [self::Indexed, self::Separation, self::DeviceN, self::Pattern], true);
    }
}
