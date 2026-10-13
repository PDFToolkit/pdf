<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

enum FontFile3Subtype: string
{
    case Type1C = 'Type1C';
    case CIDFontType0C = 'CIDFontType0C';
    case OpenType = 'OpenType';
}
