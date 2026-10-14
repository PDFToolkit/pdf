<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\FileStructure\EffectivePdfVersion;

/** Explicit UTF-16BE with one encoding BOM (ISO_32000-2_sponsored_EC3.pdf, 7.9.2.2.1, PDF p. 131). */
final readonly class Utf16BETextStringEncoding implements TextStringEncoding
{
    public function encode(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('A text string must contain valid UTF-8.');
        }
        $encoded = iconv('UTF-8', 'UTF-16BE', $text);
        if ($encoded === false) {
            throw new \InvalidArgumentException('The text string could not be encoded as UTF-16BE.');
        }
        return "\xfe\xff" . $encoded;
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        // This Unicode representation is retained from the existing PDF 1.x model.
    }
}
