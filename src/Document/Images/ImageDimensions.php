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
        $rowBytes = $this->rowBytes($layout);
        if ($rowBytes > intdiv(PHP_INT_MAX, $this->height)) {
            throw new \InvalidArgumentException('Image sample byte count exceeds the integer range.');
        }
        return $rowBytes * $this->height;
    }

    /** Rows start on byte boundaries; unused low bits of the last byte are padding. */
    public function rowBytes(SampleLayout $layout): int
    {
        $bitsPerPixel = $layout->components() * $layout->bitsPerComponent;
        if ($this->width > intdiv(PHP_INT_MAX, $bitsPerPixel)) {
            throw new \InvalidArgumentException('Image row bit count exceeds the integer range.');
        }
        $rowBits = $this->width * $bitsPerPixel;
        return intdiv($rowBits, 8) + ($rowBits % 8 === 0 ? 0 : 1);
    }
}
