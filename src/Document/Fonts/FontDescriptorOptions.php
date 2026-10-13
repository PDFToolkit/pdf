<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** External repertoire declaration needed to apply the conditional CapHeight requirement. */
final readonly class FontDescriptorOptions
{
    public function __construct(public bool $hasLatinCharacters = true)
    {
    }
}
