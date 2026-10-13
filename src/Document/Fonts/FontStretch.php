<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

enum FontStretch: string
{
    case UltraCondensed = 'UltraCondensed';
    case ExtraCondensed = 'ExtraCondensed';
    case Condensed = 'Condensed';
    case SemiCondensed = 'SemiCondensed';
    case Normal = 'Normal';
    case SemiExpanded = 'SemiExpanded';
    case Expanded = 'Expanded';
    case ExtraExpanded = 'ExtraExpanded';
    case UltraExpanded = 'UltraExpanded';
}
