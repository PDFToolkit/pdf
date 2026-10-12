<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;

/** BMC — ISO 32000-1, 14.6. */
final readonly class BeginMarkedContent extends Operator
{
    public const string SYMBOL = "BMC";

    public function __construct(public NameType $tag)
    {
        parent::__construct($tag);
    }
}
