<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Syntax\Object\NumericType;

/** Four cubic boundaries and the prescribed SC+SD−SB surface (PDF pages 202–204). */
final readonly class CoonsPatch
{
    public function __construct(public PatchControlPoints $controls, public PatchColours $colours)
    {
        if (count($controls->points) !== 12) {
            throw new \InvalidArgumentException('A complete Coons patch requires twelve control points.');
        }
    }

    public function pointAt(NumericType $u, NumericType $v): Point
    {
        if ($u->value < 0 || $u->value > 1 || $v->value < 0 || $v->value > 1) {
            throw new \InvalidArgumentException('Patch parameters must lie in the unit square.');
        }
        $p = $this->controls->points;
        $bottom = $this->curve([$p[0], $p[11], $p[10], $p[9]], $u->value);
        $top = $this->curve([$p[3], $p[4], $p[5], $p[6]], $u->value);
        $left = $this->curve([$p[0], $p[1], $p[2], $p[3]], $v->value);
        $right = $this->curve([$p[9], $p[8], $p[7], $p[6]], $v->value);
        $values = [];
        foreach (['x', 'y'] as $coordinate) {
            $bilinear = (1 - $u->value) * (1 - $v->value) * $p[0]->{$coordinate}->value + $u->value * (1 - $v->value) * $p[9]->{$coordinate}->value + (1 - $u->value) * $v->value * $p[3]->{$coordinate}->value + $u->value * $v->value * $p[6]->{$coordinate}->value;
            $values[] = new NumericType((1 - $v->value) * $bottom->{$coordinate}->value + $v->value * $top->{$coordinate}->value + (1 - $u->value) * $left->{$coordinate}->value + $u->value * $right->{$coordinate}->value - $bilinear);
        }
        return new Point($values[0], $values[1]);
    }

    /** @param array{Point,Point,Point,Point} $points */
    private function curve(array $points, float|int $t): Point
    {
        $weights = [(1 - $t) ** 3, 3 * $t * (1 - $t) ** 2, 3 * $t ** 2 * (1 - $t), $t ** 3];
        $x = $y = 0.0;
        foreach ($points as $index => $point) {
            $x += $weights[$index] * $point->x->value;
            $y += $weights[$index] * $point->y->value;
        }
        return new Point(new NumericType($x), new NumericType($y));
    }
}
