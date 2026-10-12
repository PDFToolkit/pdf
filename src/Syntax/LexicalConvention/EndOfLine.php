<?php

declare(strict_types=1);

namespace PDF\Syntax\LexicalConvention;

final readonly class EndOfLine implements \Stringable
{
    public function __construct(public EndOfLineKind $kind = EndOfLineKind::LineFeed)
    {
    }
    public function __toString(): string
    {
        return $this->kind->value;
    }
}
