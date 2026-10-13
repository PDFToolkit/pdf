<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Functions\NumberArray;

/** Explicit coordinates plus colour components, or one function parameter (PDF page 198). */
final readonly class MeshVertex
{
    public function __construct(public Point $point, public NumberArray $components)
    {
        if (count($components) === 0) {
            throw new \InvalidArgumentException('A mesh vertex needs colour data or a function parameter.');
        }
    }
}
