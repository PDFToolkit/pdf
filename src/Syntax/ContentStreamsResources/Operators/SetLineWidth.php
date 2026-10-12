<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** w — ISO 32000-1, 8.4.4. */
final readonly class SetLineWidth extends Operator
{
    public const string SYMBOL = "w";

    public function __construct(public NumericType $width)
    {
        if ($width->value < 0) {
            throw new \InvalidArgumentException('Line width must be non-negative.');
        }
        parent::__construct($width);
    }
}
