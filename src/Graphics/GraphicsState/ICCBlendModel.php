<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

/** Explicit external-profile contract: bidirectional Gray/RGB/CMYK ICC equivalence. */
enum ICCBlendModel: int
{
    case Gray = 1;
    case RGB = 3;
    case CMYK = 4;
}
