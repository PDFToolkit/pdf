<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Table162 angles are counterclockwise from left-to-right, unlike Page.Rotate. */
enum TransitionDirection
{
    case LeftToRight;
    case BottomToTop;
    case RightToLeft;
    case TopToBottom;
    case TopLeftToBottomRight;
    case None;

    public function toObject(): IntegerType|NameType
    {
        return match ($this) {
            self::LeftToRight => new IntegerType(0),
            self::BottomToTop => new IntegerType(90),
            self::RightToLeft => new IntegerType(180),
            self::TopToBottom => new IntegerType(270),
            self::TopLeftToBottomRight => new IntegerType(315),
            self::None => new NameType('None'),
        };
    }
}
