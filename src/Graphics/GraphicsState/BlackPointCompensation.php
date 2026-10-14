<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

/** PDF20_AN001-BPC.pdf p.4; case-sensitive PDF names, not booleans or rendering intents. */
enum BlackPointCompensation: string
{
    case On = 'ON';
    case Off = 'OFF';
    case ProcessorDefault = 'Default';
}
