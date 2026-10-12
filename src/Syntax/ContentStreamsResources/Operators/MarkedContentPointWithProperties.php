<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\DictionaryType;

/** DP — ISO 32000-1, 14.6. */
final readonly class MarkedContentPointWithProperties extends Operator
{
    public const string SYMBOL = "DP";

    public function __construct(public NameType $tag, public NameType|DictionaryType $properties)
    {
        parent::__construct($tag, $properties);
    }
}
