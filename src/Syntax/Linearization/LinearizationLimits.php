<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

/** Explicit capacity for the uncompressed Annex F writer and 32-bit hint fields. */
final readonly class LinearizationLimits
{
    public function __construct(public int $maximumObjects = 100_000, public int $maximumFileBytes = 4_294_967_295)
    {
        if ($maximumObjects < 4 || $maximumObjects > 4_294_967_295 || $maximumFileBytes < 1024 || $maximumFileBytes > 4_294_967_295) {
            throw new \InvalidArgumentException('Linearization capacities must fit the unsigned 32-bit format and allow a minimum file.');
        }
    }
}
