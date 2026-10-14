<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Black-point compensation changes colour conversions performed by a PDF2 processor. */
final class UseBlackPointCompensation extends Entry
{
    public function __construct(public readonly BlackPointCompensation $compensation)
    {
        parent::__construct(new NameType('UseBlackPtComp'), new NameType($compensation->value));
    }
}
