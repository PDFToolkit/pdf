<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\ArrayType;

/** Paired arrays stay synchronized, including the three-value Geospatial3D tuples. */
final readonly class GeospatialControlPoints
{
    /** @var non-empty-list<GeospatialControlPoint> */
    public array $points;
    public int $dimension;
    public bool $geographic;

    public function __construct(GeospatialControlPoint $first, GeospatialControlPoint ...$following)
    {
        $this->points = [$first, ...array_values($following)];
        $this->dimension = count($first->local->coordinates());
        $this->geographic = $first->world instanceof GeographicPosition;
        foreach ($following as $point) {
            if (count($point->local->coordinates()) !== $this->dimension || ($point->world instanceof GeographicPosition) !== $this->geographic) {
                throw new \InvalidArgumentException('A control-point set uses one dimension and one coordinate-system kind.');
            }
        }
    }

    public function local(): ArrayType
    {
        return new ArrayType(...array_merge(...array_map(static fn (GeospatialControlPoint $point): array => $point->local->coordinates(), $this->points)));
    }

    public function world(): ArrayType
    {
        return new ArrayType(...array_merge(...array_map(static fn (GeospatialControlPoint $point): array => $point->world->coordinates(), $this->points)));
    }
}
