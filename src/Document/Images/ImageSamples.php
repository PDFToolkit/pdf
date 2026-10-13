<?php

declare(strict_types=1);

namespace PDF\Document\Images;

/** Unfiltered rows of interleaved samples, ordered from the top of the image. */
final readonly class ImageSamples
{
    public function __construct(public ImageDimensions $dimensions, public SampleLayout $layout, public string $bytes)
    {
        if (strlen($bytes) !== $dimensions->sampleBytes($layout)) {
            throw new \InvalidArgumentException('Image sample length does not match its dimensions and layout.');
        }
    }
}
