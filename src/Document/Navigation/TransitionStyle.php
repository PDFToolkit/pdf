<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

enum TransitionStyle: string
{
    case Split = 'Split';
    case Blinds = 'Blinds';
    case Box = 'Box';
    case Wipe = 'Wipe';
    case Dissolve = 'Dissolve';
    case Glitter = 'Glitter';
    case Replace = 'R';
    case Fly = 'Fly';
    case Push = 'Push';
    case Cover = 'Cover';
    case Uncover = 'Uncover';
    case Fade = 'Fade';

    public function hasDirection(): bool
    {
        return in_array($this, [self::Wipe, self::Glitter, self::Fly, self::Push, self::Cover, self::Uncover], true);
    }

    public function introducedIn15(): bool
    {
        return in_array($this, [self::Fly, self::Push, self::Cover, self::Uncover, self::Fade], true);
    }
}
