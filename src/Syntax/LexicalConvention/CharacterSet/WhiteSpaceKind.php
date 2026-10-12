<?php

declare(strict_types=1);

namespace PDF\Syntax\LexicalConvention\CharacterSet;

enum WhiteSpaceKind: int
{
    case Null = 0;
    case HorizontalTab = 9;
    case LineFeed = 10;
    case FormFeed = 12;
    case CarriageReturn = 13;
    case Space = 32;
}
