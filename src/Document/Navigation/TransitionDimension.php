<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

enum TransitionDimension: string
{
    case Horizontal = 'H';
    case Vertical = 'V';
}
