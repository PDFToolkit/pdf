<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;

/** cs — ISO 32000-1, 8.6.8. */
final readonly class SetNonstrokingColorSpace extends Operator
{
    public const string SYMBOL = "cs";

    public function __construct(public NameType $colorSpace)
    {
        parent::__construct($colorSpace);
    }
}
