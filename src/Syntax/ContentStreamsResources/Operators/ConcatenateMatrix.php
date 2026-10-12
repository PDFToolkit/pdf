<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Graphics\CoordinateSystems\Matrix;

/** cm — ISO 32000-1, 8.4.4. */
final readonly class ConcatenateMatrix extends Operator
{
    public const string SYMBOL = "cm";

    public function __construct(public Matrix $matrix)
    {
        parent::__construct(...$matrix->operands());
    }
}
