<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

final readonly class RedactionLimits
{
    public function __construct(public int $operations = 1000000, public int $objects = 100000, public int $depth = 100, public int $bytes = 67108864)
    {
        if (min($operations, $objects, $depth, $bytes) < 1) {
            throw new \InvalidArgumentException('Redaction requires explicit positive processing budgets.');
        }
    }
}
