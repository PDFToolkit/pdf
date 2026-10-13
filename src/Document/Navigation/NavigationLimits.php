<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

/** Explicit construction budgets; these are application policy, not ISO numeric limits. */
final readonly class NavigationLimits
{
    public function __construct(public int $maxNodes = 100_000, public int $maxDepth = 256, public int $maxLabelBytes = 65_536)
    {
        if ($maxNodes < 1 || $maxDepth < 1 || $maxLabelBytes < 1) {
            throw new \InvalidArgumentException('Navigation construction budgets must be positive.');
        }
    }
}
