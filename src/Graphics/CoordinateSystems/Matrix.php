<?php

declare(strict_types=1);

namespace PDF\Graphics\CoordinateSystems;

use PDF\Syntax\Object\NumericType;

/** The six numbers [a b c d e f] of an affine transformation (ISO 32000-1, 8.3.3). */
final readonly class Matrix
{
    public function __construct(
        public NumericType $a,
        public NumericType $b,
        public NumericType $c,
        public NumericType $d,
        public NumericType $e,
        public NumericType $f,
    ) {
    }

    /** @return list<NumericType> */
    public function operands(): array
    {
        return [$this->a, $this->b, $this->c, $this->d, $this->e, $this->f];
    }

    public static function identity(): self
    {
        return new self(new NumericType(1), new NumericType(0), new NumericType(0), new NumericType(1), new NumericType(0), new NumericType(0));
    }
}
