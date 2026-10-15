<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** Document-local cumulative bounds for Type3 resource scope inspection. */
final class Type3ResourceBudget
{
    private int $nodes = 0;

    public function __construct(private readonly int $maximumNodes = 1000000, private readonly int $maximumDepth = 100)
    {
        if ($maximumNodes < 1 || $maximumDepth < 1) {
            throw new \InvalidArgumentException('Type3 resource budgets require positive node and depth limits.');
        }
    }

    public function visit(int $depth = 0): void
    {
        if ($depth < 0 || $depth > $this->maximumDepth || ++$this->nodes > $this->maximumNodes) {
            throw new \LengthException('Type3 resource traversal exceeds its explicit depth or node construction budget.');
        }
    }
}
