<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Document\Images\ImageDimensions;
use PDF\Document\Images\ImageSamples;
use PDF\Document\Images\SampleLayout;
use PDF\Graphics\Patterns\Shadings\MeshBitWriter;

/** Explicit row-aware unsigned sample packing; no external format is read. */
final readonly class SamplePacker
{
    public function __construct(public int $maximumBytes = 67108864)
    {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('The image sample output limit must be positive.');
        }
    }

    /** @param iterable<int> $samples Row-major, pixel-interleaved component values. */
    public function pack(ImageDimensions $dimensions, SampleLayout $layout, iterable $samples): ImageSamples
    {
        $size = $dimensions->sampleBytes($layout);
        if ($size > $this->maximumBytes) {
            throw new \LengthException('Image sample output exceeds the explicit construction limit.');
        }
        $writer = new MeshBitWriter($this->maximumBytes);
        $rowSamples = $dimensions->width * $layout->components();
        if ($rowSamples > intdiv(PHP_INT_MAX, $dimensions->height)) {
            throw new \LengthException('Image component count exceeds the platform integer range.');
        }
        $required = $rowSamples * $dimensions->height;
        $count = 0;
        foreach ($samples as $sample) {
            if ($count === $required) {
                throw new \InvalidArgumentException('Too many image component samples.');
            }
            $writer->write($sample, $layout->bitsPerComponent);
            if (++$count % $rowSamples === 0) {
                $writer->align();
            }
        }
        if ($count !== $required) {
            throw new \InvalidArgumentException('The supplied image component count does not complete every row.');
        }
        return new ImageSamples($dimensions, $layout, $writer->bytes());
    }
}
