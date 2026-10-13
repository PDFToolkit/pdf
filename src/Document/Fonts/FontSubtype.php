<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

enum FontSubtype: string
{
    case Type1 = 'Type1';
    case MMType1 = 'MMType1';
    case TrueType = 'TrueType';
    case Type3 = 'Type3';
    case CIDFontType0 = 'CIDFontType0';
    case CIDFontType2 = 'CIDFontType2';
}
