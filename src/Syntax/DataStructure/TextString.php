<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\Object\StringObject\LiteralString;

/** UTF-8 application text encoded as PDFDocEncoding or UTF-16BE with BOM (7.9.2.2). */
final class TextString extends LiteralString
{
    public function __construct(public readonly string $text)
    {
        if (preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('A text string must contain valid UTF-8.');
        }
        try {
            $bytes = (new PDFDocEncoding())->encode($text);
            if (!str_starts_with($bytes, "\xfe\xff")) {
                parent::__construct($bytes);
                return;
            }
        } catch (\InvalidArgumentException) {
            // Characters outside PDFDocEncoding require the standard UTF16BE representation.
        }
        $encoded = iconv('UTF-8', 'UTF-16BE', $text);
        if ($encoded === false) {
            throw new \InvalidArgumentException('The text string could not be encoded as UTF-16BE.');
        }
        parent::__construct("\xfe\xff" . $encoded);
    }
}
