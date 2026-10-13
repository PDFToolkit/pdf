<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** A complete simple-font metric graph with explicit widths and descriptor. */
final readonly class SimpleFontMetrics
{
    public function __construct(public SimpleWidths $widths, public FontDescriptor $descriptor)
    {
    }
}
