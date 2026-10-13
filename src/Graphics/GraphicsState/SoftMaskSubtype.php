<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

enum SoftMaskSubtype: string
{
    case Alpha = 'Alpha';
    case Luminosity = 'Luminosity';
}
