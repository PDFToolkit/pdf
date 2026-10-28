<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** Braces are lexical conditional bodies, never values on the operand stack (ISO2 p146). */
final readonly class Conditional implements Instruction
{
    public function __construct(public Program $consequent, public ?Program $alternative = null)
    {
    }

    public function toPostScript(): string
    {
        return $this->consequent->toPostScript() . ($this->alternative === null ? ' if' : ' ' . $this->alternative->toPostScript() . ' ifelse');
    }
}
