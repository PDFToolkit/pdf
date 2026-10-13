<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns;

/** Producer's explicit spacing/distortion choice from table 75, PDF page 183. */
enum TilingType: int
{
    case ConstantSpacing = 1;
    case NoDistortion = 2;
    case ConstantSpacingAndFasterTiling = 3;
}
