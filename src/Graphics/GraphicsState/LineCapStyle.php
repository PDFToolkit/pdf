<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

/** ISO 32000-1, Table 54. */
enum LineCapStyle: int
{
    case Butt = 0;
    case Round = 1;
    case ProjectingSquare = 2;
}
