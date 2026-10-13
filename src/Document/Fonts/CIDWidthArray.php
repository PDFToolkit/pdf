<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

/** 9.7.4.3: both W forms, preserving explicit runs without expanding ranges. */
final class CIDWidthArray extends ArrayType
{
    /** @var list<CIDWidthRun|CIDWidthRange> */
    public readonly array $runs;
    public function __construct(CIDWidthRun|CIDWidthRange ...$runs)
    {
        $items = [];
        new CIDRangeSet(...array_map(static fn (CIDWidthRun|CIDWidthRange $run): CIDRange => $run->range, array_values($runs)));
        foreach ($runs as $run) {
            $items[] = $run->range->first;
            if ($run instanceof CIDWidthRun) {
                $items[] = new ArrayType(...$run->widths);
            } else {
                $items[] = $run->range->last;
                $items[] = $run->value;
            }
        }
        $this->runs = array_values($runs);
        parent::__construct(...$items);
    }
    public function width(IntegerType $cid, NumericType $default = new IntegerType(1000)): NumericType
    {
        if ($cid->integer() < 0 || $cid->integer() > 65535) {
            throw new \InvalidArgumentException('CID outside 0–65535.');
        }
        foreach ($this->runs as $run) {
            if ($run->range->contains($cid->integer())) {
                return $run->width($cid->integer());
            }
        }
        return $default;
    }
}
