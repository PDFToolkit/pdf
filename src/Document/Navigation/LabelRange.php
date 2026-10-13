<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\IntegerType;

final readonly class LabelRange
{
    public function __construct(public IntegerType $firstPage, public PageLabel $label)
    {
        if ($firstPage->value < 0) {
            throw new \InvalidArgumentException('A label range starts at a nonnegative page index.');
        }
    }
}
