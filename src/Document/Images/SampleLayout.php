<?php

declare(strict_types=1);

namespace PDF\Document\Images;

/** An interleaved, eight-bit sample layout; other bit depths remain explicit future work. */
final readonly class SampleLayout
{
    public function __construct(public DeviceColorSpace $colorSpace, public int $bitsPerComponent = 8)
    {
        if ($bitsPerComponent !== 8) {
            throw new \InvalidArgumentException('This image sample model supports eight bits per component.');
        }
    }
}
