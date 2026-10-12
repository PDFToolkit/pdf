<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

/** ISO 32000-1, 8.6.5.8. */
enum RenderingIntent: string
{
    case AbsoluteColorimetric = 'AbsoluteColorimetric';
    case RelativeColorimetric = 'RelativeColorimetric';
    case Saturation = 'Saturation';
    case Perceptual = 'Perceptual';
}
