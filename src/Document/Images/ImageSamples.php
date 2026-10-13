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

    /** Read the unsigned MSB-first component before any Decode transformation. */
    public function sample(int $x, int $y, int $component): int
    {
        if ($x < 0 || $x >= $this->dimensions->width || $y < 0 || $y >= $this->dimensions->height || $component < 0 || $component >= $this->layout->components()) {
            throw new \OutOfBoundsException('Image sample coordinates must lie inside the declared layout.');
        }
        $position = $y * $this->dimensions->rowBytes($this->layout) * 8 + ($x * $this->layout->components() + $component) * $this->layout->bitsPerComponent;
        $sample = 0;
        for ($bit = 0; $bit < $this->layout->bitsPerComponent; ++$bit, ++$position) {
            $sample = ($sample << 1) | ((ord($this->bytes[intdiv($position, 8)]) >> (7 - $position % 8)) & 1);
        }
        return $sample;
    }
}
