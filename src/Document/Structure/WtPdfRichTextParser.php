<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** Explicit rich-text decoding capability; an implementation must reject unsupported textual semantics. */
interface WtPdfRichTextParser
{
    public function plainText(string $xml): string;
}
