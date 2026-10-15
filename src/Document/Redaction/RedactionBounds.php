<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\CoordinateSystems\Point;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Object\NumericType;

/** Conservative axis-aligned hull in default page space; touching counts as intersection. */
final readonly class RedactionBounds
{
    public function __construct(public float $left, public float $bottom, public float $right, public float $top)
    {
        if (!is_finite($left) || !is_finite($bottom) || !is_finite($right) || !is_finite($top) || $left > $right || $bottom > $top) {
            throw new \InvalidArgumentException('Redaction bounds require finite, ordered coordinates.');
        }
    }

    public function intersects(self $other): bool
    {
        return $this->left <= $other->right && $this->right >= $other->left && $this->bottom <= $other->top && $this->top >= $other->bottom;
    }

    public function union(self $other): self
    {
        return new self(min($this->left, $other->left), min($this->bottom, $other->bottom), max($this->right, $other->right), max($this->top, $other->top));
    }

    public function expanded(float $distance): self
    {
        if ($distance < 0 || !is_finite($distance)) {
            throw new \InvalidArgumentException('A conservative bound expansion is finite and nonnegative.');
        }
        return new self($this->left - $distance, $this->bottom - $distance, $this->right + $distance, $this->top + $distance);
    }

    public function transformed(Matrix $matrix): self
    {
        $points = [];
        foreach ([[$this->left, $this->bottom], [$this->left, $this->top], [$this->right, $this->bottom], [$this->right, $this->top]] as [$x, $y]) {
            $point = $matrix->transform(new Point(new NumericType($x), new NumericType($y)));
            $points[] = [(float) $point->x->value, (float) $point->y->value];
        }
        return new self(min(array_column($points, 0)), min(array_column($points, 1)), max(array_column($points, 0)), max(array_column($points, 1)));
    }

    public function rectangle(): Rectangle
    {
        return new Rectangle(new NumericType($this->left), new NumericType($this->bottom), new NumericType($this->right), new NumericType($this->top));
    }
}
