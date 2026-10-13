<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Explicit horizontal and vertical metric models for arbitrary normalized CID fonts. */
final readonly class CIDMetrics
{
    public function __construct(public IntegerType $defaultWidth = new IntegerType(1000), public CIDWidthArray $widths = new CIDWidthArray(), public ?CIDVerticalMetrics $vertical = null)
    {
    }
    public function width(IntegerType $cid): NumericType
    {
        return $this->widths->width($cid, $this->defaultWidth);
    }
    public function verticalMetric(IntegerType $cid): CIDVerticalMetric
    {
        return ($this->vertical ?? new CIDVerticalMetrics())->metric($cid, $this->width($cid));
    }
    /** @return list<Entry> */
    public function entries(): array
    {
        $entries = [new Entry(new NameType('DW'), $this->defaultWidth), new Entry(new NameType('W'), $this->widths)];
        if ($this->vertical !== null) {
            $entries[] = new Entry(new NameType('DW2'), $this->vertical->defaults);
            $entries[] = new Entry(new NameType('W2'), $this->vertical->widths);
        }
        return $entries;
    }
}
