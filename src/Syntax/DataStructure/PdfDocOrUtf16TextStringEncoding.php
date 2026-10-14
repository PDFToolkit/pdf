<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\FileStructure\EffectivePdfVersion;

/** Unambiguous PDFDocEncoding or UTF-16BE (ISO_32000-2_sponsored_EC3.pdf, PDF pp. 131–132). */
final readonly class PdfDocOrUtf16TextStringEncoding implements TextStringEncoding
{
    public function encode(string $text): string
    {
        try {
            $bytes = (new PDFDocEncoding())->encode($text);
            if (!str_starts_with($bytes, "\xfe\xff") && !str_starts_with($bytes, "\xef\xbb\xbf")) {
                return $bytes;
            }
        } catch (\InvalidArgumentException) {
            // Unicode outside PDFDocEncoding uses the unambiguous UTF-16BE representation.
        }
        return (new Utf16BETextStringEncoding())->encode($text);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        // The existing PDF 1.x representation remains available in PDF 2.0.
    }
}
