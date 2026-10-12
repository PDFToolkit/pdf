<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources;

/** A CharProcs stream, beginning with explicit glyph metrics (9.6.4). */
final readonly class Type3GlyphContentStream extends ContentStreams
{
    protected function isType3Glyph(): bool
    {
        return true;
    }
}
