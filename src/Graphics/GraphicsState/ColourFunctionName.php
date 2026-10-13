<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

enum ColourFunctionName: string
{
    case Identity = 'Identity';
    case Default = 'Default';
}
