<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\NameType;

/** Table 117: CID and glyph index are explicitly identical for the embedded Type 2 program. */
final readonly class CIDIdentityGlyphMap
{
    public function object(): NameType
    {
        return new NameType('Identity');
    }
}
