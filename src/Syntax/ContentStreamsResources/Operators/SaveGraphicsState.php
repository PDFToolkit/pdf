<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** q — ISO 32000-1, 8.4.4. */
final readonly class SaveGraphicsState extends Operator
{
    public const string SYMBOL = "q";

    public function __construct()
    {
        parent::__construct();
    }
}
