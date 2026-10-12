<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** s — ISO 32000-1, 8.5.3. */
final readonly class CloseAndStrokePath extends Operator
{
    public const string SYMBOL = "s";

    public function __construct()
    {
        parent::__construct();
    }
}
