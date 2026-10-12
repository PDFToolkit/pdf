<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** M — ISO 32000-1, 8.4.4. */
final readonly class SetMiterLimit extends Operator
{
    public const string SYMBOL = "M";

    public function __construct(public NumericType $limit)
    {
        if ($limit->value < 1) {
            throw new \InvalidArgumentException('The miter limit must be at least one.');
        }
        parent::__construct($limit);
    }
}
