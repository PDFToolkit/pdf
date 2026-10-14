<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

/** One explicit LPTS/GPTS correspondence; the dimensionality is never guessed. */
final readonly class GeospatialControlPoint
{
    public function __construct(public LocalPosition $local, public GeographicPosition|ProjectedPosition $world)
    {
        if (count($local->coordinates()) !== count($world->coordinates())) {
            throw new \InvalidArgumentException('Corresponding local and world points have the same dimension.');
        }
        if ($local->z === null && ($local->x->value < 0 || $local->x->value > 1 || $local->y->value < 0 || $local->y->value > 1)) {
            throw new \InvalidArgumentException('Two-dimensional LPTS positions belong to the unit square.');
        }
    }
}
