<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\Encodings;

/** Built-in font vectors and PDF's three separately selectable predefined encodings. */
enum EncodingVectorKind: string
{
    case Standard = 'Standard';
    case MacRoman = 'MacRoman';
    case MacOSRoman = 'MacOSRoman';
    case WinAnsi = 'WinAnsi';
    case MacExpert = 'MacExpert';
    case Symbol = 'Symbol';
    case ZapfDingbats = 'ZapfDingbats';
}
