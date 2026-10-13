<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

final readonly class CIDWidthRun
{
    public CIDRange $range;
    /** @var list<NumericType> */
    public array $widths;
    public function __construct(IntegerType $first, NumericType ...$widths)
    {
        if ($widths === [] || $first->integer() > 65536 - count($widths)) {
            throw new \InvalidArgumentException('A CID width run is nonempty and fits the CID domain.');
        }
        $this->range = new CIDRange($first, new IntegerType($first->integer() + count($widths) - 1));
        $this->widths = array_values($widths);
    }
    public function width(int $cid): NumericType
    {
        return $this->widths[$cid - $this->range->first->integer()] ?? throw new \OutOfBoundsException('CID outside this width run.');
    }
}
