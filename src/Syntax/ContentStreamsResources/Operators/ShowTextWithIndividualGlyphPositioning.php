<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\NumericType;

/** TJ — ISO 32000-1, 9.4.3. */
final readonly class ShowTextWithIndividualGlyphPositioning extends Operator
{
    public const string SYMBOL = "TJ";

    public function __construct(public ArrayType $items)
    {
        foreach ($items->items as $item) {
            if (!$item instanceof StringType && !$item instanceof NumericType) {
                throw new \InvalidArgumentException('A TJ array may contain only strings and numbers.');
            }
        }
        parent::__construct($items);
    }
}
