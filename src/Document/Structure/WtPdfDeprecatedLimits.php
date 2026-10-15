<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

final readonly class WtPdfDeprecatedLimits
{
    public function __construct(public int $maximumObjects = 100000, public int $maximumEdges = 1000000, public int $maximumDepth = 256, public int $maximumOperations = 1000000)
    {
        if (min($maximumObjects, $maximumEdges, $maximumDepth, $maximumOperations) < 1) {
            throw new \InvalidArgumentException('Deprecated-feature inspection requires positive explicit graph and operation budgets.');
        }
    }
}
