<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\FileStructure\EffectivePdfVersion;

/** Encoding and document applicability of human-readable text (ISO_32000-2_sponsored_EC3.pdf, 7.9.2.2.1, PDF p. 131). */
interface TextStringEncoding
{
    public function encode(string $text): string;

    public function validateVersion(EffectivePdfVersion $version): void;
}
