<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

enum BaseState: string
{
    case On = 'ON';
    case Off = 'OFF';
    case Unchanged = 'Unchanged';
}
