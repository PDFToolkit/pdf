<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

/** Actual glyph outline hull and advance in the PDF thousand-unit glyph coordinate system. */
final readonly class RedactionGlyphGeometry
{
    public function __construct(public RedactionBounds $outline, public float $advance)
    {
        if (!is_finite($advance)) {
            throw new \InvalidArgumentException('A verified glyph advance is finite.');
        }
    }
}
