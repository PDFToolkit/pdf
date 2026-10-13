<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Syntax\Object\NumericType;

/** Sixteen controls, including four interior points, evaluated by Bernstein products (pages 206–209). */
final readonly class TensorProductPatch
{
    public function __construct(public PatchControlPoints $controls, public PatchColours $colours)
    {
        if (count($controls->points) !== 16) {
            throw new \InvalidArgumentException('A complete tensor-product patch requires sixteen control points.');
        }
    }

    public function pointAt(NumericType $u, NumericType $v): Point
    {
        if ($u->value < 0 || $u->value > 1 || $v->value < 0 || $v->value > 1) {
            throw new \InvalidArgumentException('Patch parameters must lie in the unit square.');
        }
        $columns = [[0, 1, 2, 3], [11, 12, 13, 4], [10, 15, 14, 5], [9, 8, 7, 6]];
        $bu = $this->bernstein($u->value);
        $bv = $this->bernstein($v->value);
        $x = $y = 0.0;
        foreach ($columns as $i => $column) {
            foreach ($column as $j => $index) {
                $point = $this->controls->points[$index];
                $x += $point->x->value * $bu[$i] * $bv[$j];
                $y += $point->y->value * $bu[$i] * $bv[$j];
            }
        }
        return new Point(new NumericType($x), new NumericType($y));
    }

    /** @return array{float|int,float|int,float|int,float|int} */
    private function bernstein(float|int $t): array
    {
        return [(1 - $t) ** 3, 3 * $t * (1 - $t) ** 2, 3 * $t ** 2 * (1 - $t), $t ** 3];
    }
}
