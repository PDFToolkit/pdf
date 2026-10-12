<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;

/** Do — ISO 32000-1, 8.8. */
final readonly class InvokeXObject extends Operator
{
    public const string SYMBOL = "Do";

    public function __construct(public NameType $resourceName)
    {
        parent::__construct($resourceName);
    }
}
