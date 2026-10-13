<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

/** A complete independent Gouraud triangle; the second/third stored flags are insignificant. */
final readonly class FreeFormTriangle
{
    public function __construct(public MeshVertex $a, public MeshVertex $b, public MeshVertex $c)
    {
    }
}
