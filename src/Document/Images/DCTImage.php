<?php

declare(strict_types=1);

namespace PDF\Document\Images;

/** PDF image data already encoded for /DCTDecode; no external format is parsed. */
final readonly class DCTImage
{
    public function __construct(
        public string $encodedBytes,
        public ImageDimensions $dimensions,
        public SampleLayout $layout,
        public int $colorTransform,
        public bool $invertedComponents = false,
    ) {
        if ($encodedBytes === '') {
            throw new \InvalidArgumentException('DCT image data must not be empty.');
        }
        if (!in_array($colorTransform, [0, 1], true)) {
            throw new \InvalidArgumentException('DCT colour transform must be zero or one.');
        }
        if ($layout->bitsPerComponent !== 8) {
            throw new \InvalidArgumentException('DCTDecode delivers eight-bit component samples.');
        }
        if ($invertedComponents && $layout->space()->family() !== \PDF\Graphics\ColourSpaces\ColourSpaceFamily::DeviceCMYK) {
            throw new \InvalidArgumentException('Inverted DCT components require a CMYK colour space.');
        }
    }
}
