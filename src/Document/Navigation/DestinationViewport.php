<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\NumericType;

/** Explicit window dimensions, expressed in the default user-space axes at zoom one. */
final readonly class DestinationViewport
{
    public function __construct(public NumericType $width, public NumericType $height, public ?ViewPosition $current = null)
    {
        if ($width->value <= 0 || $height->value <= 0) {
            throw new \InvalidArgumentException('A destination viewport has positive dimensions.');
        }
    }
}
