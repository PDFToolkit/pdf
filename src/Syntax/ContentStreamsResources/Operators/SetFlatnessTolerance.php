<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** i — ISO 32000-1, 8.4.4. */
final readonly class SetFlatnessTolerance extends Operator
{
    public const string SYMBOL = "i";

    public function __construct(public NumericType $flatness)
    {
        if ($flatness->value < 0 || $flatness->value > 100) {
            throw new \InvalidArgumentException('Flatness must be between zero and 100.');
        }
        parent::__construct($flatness);
    }
}
