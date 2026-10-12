<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NumericType;

/** SC — ISO 32000-1, 8.6.8; component count depends on the current colour space. */
final readonly class SetStrokingColor extends Operator
{
    public const string SYMBOL = 'SC';

    public function __construct(NumericType ...$components)
    {
        if ($components === []) {
            throw new \InvalidArgumentException('At least one colour component is required.');
        }
        parent::__construct(...$components);
    }
}
