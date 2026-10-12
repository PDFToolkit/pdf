<?php

declare(strict_types=1);

namespace PDF\Syntax\LexicalConvention\CharacterSet;

final readonly class WhiteSpace extends Character
{
    public function __construct(public WhiteSpaceKind $kind)
    {
        parent::__construct($kind->value);
    }
}
