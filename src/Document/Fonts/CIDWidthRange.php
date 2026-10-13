<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\NumericType;

final readonly class CIDWidthRange
{
    public function __construct(public CIDRange $range, public NumericType $value)
    {
    }
    public function width(int $cid): NumericType
    {
        return $this->range->contains($cid) ? $this->value : throw new \OutOfBoundsException('CID outside this width range.');
    }
}
