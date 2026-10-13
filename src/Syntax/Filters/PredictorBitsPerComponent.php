<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

/** Table 8 permits 1/2/4/8 bits, and 16 bits from PDF 1.5. */
enum PredictorBitsPerComponent: int
{
    case One = 1;
    case Two = 2;
    case Four = 4;
    case Eight = 8;
    case Sixteen = 16;
}
