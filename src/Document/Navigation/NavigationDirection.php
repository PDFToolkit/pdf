<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

enum NavigationDirection
{
    case Forward;
    case Backward;
    case RandomAccess;
}
