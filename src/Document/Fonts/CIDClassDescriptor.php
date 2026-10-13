<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

final readonly class CIDClassDescriptor
{
    public function __construct(public CIDGlyphClass $class, public CIDFontMetricOverride $descriptor)
    {
    }
}
