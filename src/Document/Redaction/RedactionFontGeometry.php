<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Syntax\Object\DictionaryType;

/**
 * Explicit font-program parser capability. Return actual program-derived geometry
 * bound to this font/code, or null. A FontBBox/Widths assertion alone is not proof.
 */
interface RedactionFontGeometry
{
    public function glyph(DictionaryType $font, int $code): ?RedactionGlyphGeometry;
}
