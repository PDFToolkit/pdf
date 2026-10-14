<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** Table 269 PCSM: 12 coefficients; EC3 #358 Completed and #534 Accepted. */
final class ProjectedCoordinateSystemMatrix extends ArrayType
{
    /** @var list<NumericType> */
    private readonly array $coefficients;
    public function __construct(NumericType ...$coefficients)
    {
        if (count($coefficients) !== 12) {
            throw new \InvalidArgumentException('PCSM contains twelve affine coefficients in row order.');
        }
        $this->coefficients = array_values($coefficients);
        parent::__construct(...$coefficients);
    }

    /** Row vector [x y z 1], with the implicit final column [0 0 0 1]. */
    public function transform(LocalPosition $position): ProjectedPosition
    {
        $matrix = $this->coefficients;
        $x = $position->x->value;
        $y = $position->y->value;
        $z = $position->z === null ? 0 : $position->z->value;
        return new ProjectedPosition(
            new NumericType($x * $matrix[0]->value + $y * $matrix[3]->value + $z * $matrix[6]->value + $matrix[9]->value),
            new NumericType($x * $matrix[1]->value + $y * $matrix[4]->value + $z * $matrix[7]->value + $matrix[10]->value),
            $position->z === null ? null : new NumericType($x * $matrix[2]->value + $y * $matrix[5]->value + $z * $matrix[8]->value + $matrix[11]->value),
        );
    }
}
