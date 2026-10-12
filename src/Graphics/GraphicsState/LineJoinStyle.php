<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

/** ISO 32000-1, Table 55. */
enum LineJoinStyle: int
{
    case Miter = 0;
    case Round = 1;
    case Bevel = 2;
}
