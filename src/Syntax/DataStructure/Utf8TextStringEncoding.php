<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;

/** Explicit PDF 2.0 UTF-8 with BOM (ISO_32000-2_sponsored_EC3.pdf, 7.9.2.2.1, PDF p. 131). */
final readonly class Utf8TextStringEncoding implements TextStringEncoding
{
    public function encode(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('A text string must contain valid UTF-8.');
        }
        return "\xef\xbb\xbf" . $text;
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'UTF-8 text strings');
    }
}
