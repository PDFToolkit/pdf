<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns;

/** Coloured or stencil cell painting (table 75, PDF page 183). */
enum PaintType: int
{
    case Coloured = 1;
    case Uncoloured = 2;
}
