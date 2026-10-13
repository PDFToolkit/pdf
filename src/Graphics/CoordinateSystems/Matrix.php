<?php

declare(strict_types=1);

namespace PDF\Graphics\CoordinateSystems;

use PDF\Syntax\Object\NumericType;

/** The six numbers [a b c d e f] of an affine transformation (ISO 32000-1, 8.3.3). */
final readonly class Matrix
{
    public function __construct(
        public NumericType $a = new NumericType(1),
        public NumericType $b = new NumericType(0),
        public NumericType $c = new NumericType(0),
        public NumericType $d = new NumericType(1),
        public NumericType $e = new NumericType(0),
        public NumericType $f = new NumericType(0),
    ) {
    }

    /** @return list<NumericType> */
    public function operands(): array
    {
        return [$this->a, $this->b, $this->c, $this->d, $this->e, $this->f];
    }

    public function transform(Point $point): Point
    {
        return new Point(
            new NumericType($point->x->value * $this->a->value + $point->y->value * $this->c->value + $this->e->value),
            new NumericType($point->x->value * $this->b->value + $point->y->value * $this->d->value + $this->f->value)
        );
    }

    /** Apply this matrix first, then the next matrix (row-vector convention, 8.3.3). */
    public function then(self $next): self
    {
        return new self(
            new NumericType($this->a->value * $next->a->value + $this->b->value * $next->c->value),
            new NumericType($this->a->value * $next->b->value + $this->b->value * $next->d->value),
            new NumericType($this->c->value * $next->a->value + $this->d->value * $next->c->value),
            new NumericType($this->c->value * $next->b->value + $this->d->value * $next->d->value),
            new NumericType($this->e->value * $next->a->value + $this->f->value * $next->c->value + $next->e->value),
            new NumericType($this->e->value * $next->b->value + $this->f->value * $next->d->value + $next->f->value),
        );
    }

    public function inverse(): self
    {
        $determinant = $this->a->value * $this->d->value - $this->b->value * $this->c->value;
        if ($determinant === 0 || $determinant === 0.0) {
            throw new \LogicException('A singular affine matrix has no inverse.');
        }
        return new self(
            new NumericType($this->d->value / $determinant),
            new NumericType(-$this->b->value / $determinant),
            new NumericType(-$this->c->value / $determinant),
            new NumericType($this->a->value / $determinant),
            new NumericType(($this->c->value * $this->f->value - $this->d->value * $this->e->value) / $determinant),
            new NumericType(($this->b->value * $this->e->value - $this->a->value * $this->f->value) / $determinant)
        );
    }

}
