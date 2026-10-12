<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;

/** gs — ISO 32000-1, 8.4.4. */
final readonly class SetGraphicsStateParameters extends Operator
{
    public const string SYMBOL = "gs";

    public function __construct(public NameType $resourceName)
    {
        parent::__construct($resourceName);
    }
}
