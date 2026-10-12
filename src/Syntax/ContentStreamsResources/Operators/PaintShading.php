<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;

/** sh — ISO 32000-1, 8.7.4.3. */
final readonly class PaintShading extends Operator
{
    public const string SYMBOL = "sh";

    public function __construct(public NameType $resourceName)
    {
        parent::__construct($resourceName);
    }
}
