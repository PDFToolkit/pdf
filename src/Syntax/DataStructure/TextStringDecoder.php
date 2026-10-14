<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\StringType;

/** Version-scoped BOM interpretation (ISO_32000-2_sponsored_EC3.pdf, 7.9.2.2.1, PDF pp. 131–132). */
final readonly class TextStringDecoder
{
    public function __construct(public PdfVersion $version = PdfVersion::PDF17)
    {
    }

    public function decode(StringType $string): string
    {
        if ($this->version === PdfVersion::PDF20 && str_starts_with($string->value, "\xef\xbb\xbf")) {
            $text = substr($string->value, 3);
            if (preg_match('//u', $text) !== 1) {
                throw new \InvalidArgumentException('UTF-8 text has valid Unicode scalar values.');
            }
            return $text;
        }
        if (!str_starts_with($string->value, "\xfe\xff")) {
            return (new PDFDocEncoding())->decode($string->value);
        }
        $bytes = substr($string->value, 2);
        if (strlen($bytes) % 2 !== 0) {
            throw new \InvalidArgumentException('UTF16BE text has complete two-byte code units.');
        }
        $text = @iconv('UTF-16BE', 'UTF-8', $bytes);
        if ($text === false) {
            throw new \InvalidArgumentException('UTF16BE text has valid paired surrogates.');
        }
        return $text;
    }
}
