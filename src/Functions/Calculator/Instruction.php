<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

interface Instruction
{
    public function toPostScript(): string;
}
