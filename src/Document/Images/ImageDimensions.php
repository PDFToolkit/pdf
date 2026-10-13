<?php

declare(strict_types=1);

namespace PDF\Document\Images;

/** Pixel dimensions, independent of the transformation used to paint the image. */
final readonly class ImageDimensions
{
    public function __construct(public int $width, public int $height)
    {
        if ($width < 1 || $height < 1 || $width > intdiv(PHP_INT_MAX, $height)) {
            throw new \InvalidArgumentException('Image dimensions must be positive and their product must fit an integer.');
        }
    }

    public function pixels(): int
    {
        return $this->width * $this->height;
    }

    public function sampleBytes(SampleLayout $layout): int
    {
        $components = $layout->colorSpace->components();
        if ($this->pixels() > intdiv(PHP_INT_MAX, $components)) {
            throw new \InvalidArgumentException('Image sample byte count exceeds the integer range.');
        }
        return $this->pixels() * $components;
    }
}
