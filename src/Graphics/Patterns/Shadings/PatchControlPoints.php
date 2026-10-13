<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

/** Ordered Bézier control points; patch type and edge reuse determine the required count. */
final readonly class PatchControlPoints
{
    /** @var non-empty-list<Point> */
    public array $points;

    public function __construct(Point ...$points)
    {
        if ($points === []) {
            throw new \InvalidArgumentException('Patch control points cannot be empty.');
        }
        $this->points = array_values($points);
    }
}
