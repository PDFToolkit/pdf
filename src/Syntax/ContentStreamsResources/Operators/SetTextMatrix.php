<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Graphics\CoordinateSystems\Matrix;

/** Tm — ISO 32000-1, 9.4.2. */
final readonly class SetTextMatrix extends Operator
{
    public const string SYMBOL = "Tm";

    public function __construct(public Matrix $matrix)
    {
        parent::__construct(...$matrix->operands());
    }
}
