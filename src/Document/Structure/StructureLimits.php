<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** Explicit construction resource limits, rather than additional PDF-format restrictions. */
final readonly class StructureLimits
{
    public function __construct(public int $maximumElements = 100000, public int $maximumParentArrayLength = 65536, public int $maximumColumns = 65536)
    {
        if ($maximumElements < 1 || $maximumParentArrayLength < 1 || $maximumColumns < 1) {
            throw new \InvalidArgumentException('Structure construction limits must be positive.');
        }
    }
}
