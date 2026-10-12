<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;

/** MP — ISO 32000-1, 14.6. */
final readonly class MarkedContentPoint extends Operator
{
    public const string SYMBOL = "MP";

    public function __construct(public NameType $tag)
    {
        parent::__construct($tag);
    }
}
