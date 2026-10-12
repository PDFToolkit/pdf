<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** EX — ISO 32000-1, 7.8.2. */
final readonly class EndCompatibilitySection extends Operator
{
    public const string SYMBOL = "EX";

    public function __construct()
    {
        parent::__construct();
    }
}
