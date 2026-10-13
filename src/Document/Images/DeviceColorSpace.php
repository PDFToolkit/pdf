<?php

declare(strict_types=1);

namespace PDF\Document\Images;

/** Device image colour spaces (ISO 32000-1, 8.6.4). */
enum DeviceColorSpace: string
{
    case Gray = 'DeviceGray';
    case RGB = 'DeviceRGB';
    case CMYK = 'DeviceCMYK';

    public function components(): int
    {
        return match ($this) {
            self::Gray => 1,
            self::RGB => 3,
            self::CMYK => 4,
        };
    }
}
