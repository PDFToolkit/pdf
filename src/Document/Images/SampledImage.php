<?php

declare(strict_types=1);

namespace PDF\Document\Images;

/** Colour samples and an optional separate, unassociated opacity channel. */
final readonly class SampledImage
{
    public function __construct(public ImageSamples $samples, public ?ImageSamples $alpha = null)
    {
        if ($alpha !== null && (
            $alpha->layout->colorSpace !== DeviceColorSpace::Gray
            || $alpha->dimensions->width !== $samples->dimensions->width
            || $alpha->dimensions->height !== $samples->dimensions->height
        )) {
            throw new \InvalidArgumentException('The alpha image must be grayscale and match the colour image dimensions.');
        }
    }
}
