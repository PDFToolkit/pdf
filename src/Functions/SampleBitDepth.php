<?php

declare(strict_types=1);

namespace PDF\Functions;

enum SampleBitDepth: int
{
    case One = 1;
    case Two = 2;
    case Four = 4;
    case Eight = 8;
    case Twelve = 12;
    case Sixteen = 16;
    case TwentyFour = 24;
    case ThirtyTwo = 32;
}
