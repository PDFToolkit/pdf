<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

/** Tables 111/112: contiguous byte-code widths, exactly LastChar−FirstChar+1. */
final class SimpleWidths extends ArrayType
{
    public readonly IntegerType $last;
    public function __construct(public readonly IntegerType $first, NumericType ...$widths)
    {
        if ($widths === [] || $first->integer() < 0 || $first->integer() > 256 - count($widths)) {
            throw new \InvalidArgumentException('Simple font widths cover a nonempty byte-code range.');
        }
        $this->last = new IntegerType($first->integer() + count($widths) - 1);
        parent::__construct(...$widths);
    }

    public function width(IntegerType $code, NumericType $missing = new NumericType(0)): NumericType
    {
        if ($code->integer() < 0 || $code->integer() > 255) {
            throw new \InvalidArgumentException('Simple font character codes are bytes.');
        }
        $width = $this->items[$code->integer() - $this->first->integer()] ?? null;
        return $width instanceof NumericType ? $width : $missing;
    }
}
