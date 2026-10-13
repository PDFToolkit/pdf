<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\Object\StringType;

/** 7.9.2.2: decode PDF 1.x text, distinguishing the UTF16BE BOM from PDFDocEncoding. */
final readonly class TextStringDecoder
{
    public function decode(StringType $string): string
    {
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
