<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** SCN — ISO 32000-1, 8.6.8; an optional final name identifies a pattern resource. */
final readonly class SetStrokingColorWithPattern extends Operator
{
    public const string SYMBOL = 'SCN';

    public function __construct(NumericType|NameType ...$componentsAndPattern)
    {
        if ($componentsAndPattern === []) {
            throw new \InvalidArgumentException('Colour components or a pattern resource name are required.');
        }
        foreach ($componentsAndPattern as $position => $operand) {
            if ($operand instanceof NameType && $position !== array_key_last($componentsAndPattern)) {
                throw new \InvalidArgumentException('The pattern resource name must be the final colour operand.');
            }
        }
        parent::__construct(...$componentsAndPattern);
    }
}
