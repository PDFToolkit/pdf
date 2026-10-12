<?php

declare(strict_types=1);

namespace PDF\Functions;

enum InterpolationOrder: int
{
    case Linear = 1;
    case Cubic = 3;
}
