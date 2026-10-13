<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\Encodings;

/** Table 114 and Annex D.1: Standard/PDFDoc/Symbol are not selectable predefined encoding names. */
enum PredefinedEncoding: string
{
    case MacRoman = 'MacRomanEncoding';
    case WinAnsi = 'WinAnsiEncoding';
    case MacExpert = 'MacExpertEncoding';

    public function vector(): EncodingVector
    {
        return new EncodingVector(match ($this) {
            self::MacRoman => EncodingVectorKind::MacRoman,
            self::WinAnsi => EncodingVectorKind::WinAnsi,
            self::MacExpert => EncodingVectorKind::MacExpert,
        });
    }
}
