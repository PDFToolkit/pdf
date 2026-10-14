<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\DictionaryType;

/** Exact same-length file bytes and the AuthCode dictionary actually emitted. */
final readonly class PdfMacFinalization
{
    public function __construct(public string $bytes, public DictionaryType $authCode)
    {
    }
}
