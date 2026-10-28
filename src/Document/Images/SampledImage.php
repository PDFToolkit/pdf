<?php

declare(strict_types=1);

namespace PDF\Document\Images;

use PDF\Graphics\GraphicsState\RenderingIntent;

/** Colour samples and an optional separate, unassociated opacity channel. */
final readonly class SampledImage
{
    public function __construct(public ImageSamples $samples, public ?ImageSamples $alpha = null, public ?RenderingIntent $intent = null)
    {
        if ($alpha !== null && (
            $alpha->layout->space()->family() !== \PDF\Graphics\ColourSpaces\ColourSpaceFamily::DeviceGray
            || $alpha->dimensions->width !== $samples->dimensions->width
            || $alpha->dimensions->height !== $samples->dimensions->height
        )) {
            throw new \InvalidArgumentException('The alpha image must be grayscale and match the colour image dimensions.');
        }
    }
}
