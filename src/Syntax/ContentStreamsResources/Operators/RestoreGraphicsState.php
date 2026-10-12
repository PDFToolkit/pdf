<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;

/** Q — ISO 32000-1, 8.4.4. */
final readonly class RestoreGraphicsState extends Operator
{
    public const string SYMBOL = "Q";

    public function __construct()
    {
        parent::__construct();
    }
}
