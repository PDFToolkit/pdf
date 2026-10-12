<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** EMC — ISO 32000-1, 14.6. */
final readonly class EndMarkedContent extends Operator
{
    public const string SYMBOL = "EMC";

    public function __construct()
    {
        parent::__construct();
    }
}
