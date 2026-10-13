<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

/** Undefined-glyph fallback may overlap ordinary CID mappings, ISO 9.7.6.3, p.289. */
final readonly class NotdefChar extends CidChar
{
    public function operator(): string
    {
        return 'notdefchar';
    }
    public function notdef(): bool
    {
        return true;
    }
}
