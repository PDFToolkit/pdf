<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

enum CMapWritingMode: int
{
    case Horizontal = 0;
    case Vertical = 1;
}
