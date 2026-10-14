<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\Object\StringObject\LiteralString;

/** Immutable application text with an explicit encoding object (ISO_32000-2_sponsored_EC3.pdf, 7.9.2.2.1, PDF pp. 131–132). */
final class TextString extends LiteralString implements VersionedComponent
{
    public function __construct(
        public readonly string $text,
        public readonly TextStringEncoding $encoding = new PdfDocOrUtf16TextStringEncoding(),
    ) {
        if (preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('A text string must contain valid UTF-8.');
        }
        parent::__construct($encoding->encode($text));
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $this->encoding->validateVersion($version);
    }
}
