<?php

declare(strict_types=1);

namespace PDF\Syntax\LexicalConvention\CharacterSet;

enum DelimiterKind: int
{
    case LeftParenthesis = 40;
    case RightParenthesis = 41;
    case LessThanSign = 60;
    case GreaterThanSign = 62;
    case LeftSquareBracket = 91;
    case RightSquareBracket = 93;
    case LeftCurlyBracket = 123;
    case RightCurlyBracket = 125;
    case Solidus = 47;
    case PercentSign = 37;
}
