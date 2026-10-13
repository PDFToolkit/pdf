<?php

declare(strict_types=1);

namespace PDF\Document\Images;

/** Structurally inspected DCT data; entropy decoding remains the PDF reader's responsibility. */
final readonly class JpegImage
{
    public function __construct(
        public ImageFileBytes $file,
        public ImageDimensions $dimensions,
        public SampleLayout $layout,
        public int $colorTransform,
        public bool $invertedComponents = false,
    ) {
        if (!in_array($colorTransform, [0, 1], true)) {
            throw new \InvalidArgumentException('JPEG colour transform must be zero or one.');
        }
        if ($invertedComponents && $layout->colorSpace !== DeviceColorSpace::CMYK) {
            throw new \InvalidArgumentException('Inverted JPEG components are supported only for Adobe CMYK.');
        }
    }
}
