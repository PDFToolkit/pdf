<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** An application's explicit input-byte budget for one TrueType font program. */
final readonly class FontProgramLimits
{
    public function __construct(public int $maxBytes = 33554432)
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('The font program byte budget must be positive.');
        }
    }
}
