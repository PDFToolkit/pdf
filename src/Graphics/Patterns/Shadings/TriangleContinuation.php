<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Syntax\Object\IntegerType;

/** Flag 1 reuses BC; flag 2 reuses AC of the previous triangle (PDF page 199). */
final readonly class TriangleContinuation
{
    public function __construct(public IntegerType $edge, public MeshVertex $vertex)
    {
        if (!in_array($edge->integer(), [1, 2], true)) {
            throw new \InvalidArgumentException('A triangle continuation uses edge flag one or two.');
        }
    }
}
