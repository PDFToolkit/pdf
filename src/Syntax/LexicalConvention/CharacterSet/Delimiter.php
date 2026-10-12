<?php

declare(strict_types=1);

namespace PDF\Syntax\LexicalConvention\CharacterSet;

final readonly class Delimiter extends Character
{
    public function __construct(public DelimiterKind $kind)
    {
        parent::__construct($kind->value);
    }
}
