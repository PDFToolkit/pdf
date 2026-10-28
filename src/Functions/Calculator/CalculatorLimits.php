<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** Explicit implementation budgets; ISO2 7.10.5 p147 requires stack capacity >=100 and writer nesting <=255. */
final readonly class CalculatorLimits
{
    public function __construct(public int $maxStack = 100, public int $maxOperations = 1_000_000, public int $maxInstructions = 100_000, public int $maxNesting = 255)
    {
        if ($maxStack < 100 || $maxOperations < 1 || $maxInstructions < 1 || $maxNesting < 1 || $maxNesting > 255) {
            throw new \InvalidArgumentException('Calculator budgets require stack >=100, positive work limits, and brace nesting in 1..255.');
        }
    }
}
