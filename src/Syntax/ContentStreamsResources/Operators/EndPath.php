<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** n — ISO 32000-1, 8.5.3. */
final readonly class EndPath extends Operator
{
    public const string SYMBOL = "n";

    public function __construct()
    {
        parent::__construct();
    }
}
