<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\IntegerType;

/** Encoding values 10–14 in Table10 explicitly use the same PNG row filter throughout. */
final readonly class FixedPngFilterSelection implements PngFilterSelection
{
    public function __construct(public PngRowFilter $filter)
    {
    }

    public function predictor(): IntegerType
    {
        return new IntegerType(10 + $this->filter->value);
    }

    public function select(string $row, string $previous, PredictorLayout $layout): PngRowFilter
    {
        return $this->filter;
    }
}
