<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\NumericType;

/** Upper-left position and positive magnification in default user-space axes. */
final readonly class ViewPosition
{
    public function __construct(public NumericType $left, public NumericType $top, public NumericType $zoom)
    {
        if ($zoom->value <= 0) {
            throw new \InvalidArgumentException('An actual view has a positive magnification.');
        }
    }
}
