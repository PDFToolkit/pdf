<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;

/** CS — ISO 32000-1, 8.6.8. */
final readonly class SetStrokingColorSpace extends Operator
{
    public const string SYMBOL = "CS";

    public function __construct(public NameType $colorSpace)
    {
        parent::__construct($colorSpace);
    }
}
