<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

enum TransitionMotion: string
{
    case Inward = 'I';
    case Outward = 'O';
}
