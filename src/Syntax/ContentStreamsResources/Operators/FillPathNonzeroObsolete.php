<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** F — ISO 32000-1, 8.5.3. */
final readonly class FillPathNonzeroObsolete extends Operator
{
    public const string SYMBOL = "F";

    public function __construct()
    {
        parent::__construct();
    }
}
