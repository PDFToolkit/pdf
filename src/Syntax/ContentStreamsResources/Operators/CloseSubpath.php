<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** h — ISO 32000-1, 8.5.2. */
final readonly class CloseSubpath extends Operator
{
    public const string SYMBOL = "h";

    public function __construct()
    {
        parent::__construct();
    }
}
