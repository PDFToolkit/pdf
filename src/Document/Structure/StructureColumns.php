<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

/** Explicit expansion of ColumnGap/ColumnWidths repetition and truncation (table 346, page 616). */
final readonly class StructureColumns
{
    public function __construct(public IntegerType $count, public NumericType|ArrayType $widths, public NumericType|ArrayType $gaps, StructureLimits $limits = new StructureLimits())
    {
        if ($count->integer() < 1 || $count->integer() > $limits->maximumColumns) {
            throw new \InvalidArgumentException('A column count must be positive and fit its explicit construction limit.');
        }
        $this->expand($widths, $count->integer());
        $this->expand($gaps, max(0, $count->integer() - 1));
    }

    /** @return list<NumericType> */
    public function columnWidths(): array
    {
        return $this->expand($this->widths, $this->count->integer());
    }

    /** @return list<NumericType> */
    public function columnGaps(): array
    {
        return $this->expand($this->gaps, max(0, $this->count->integer() - 1));
    }

    /** @return list<NumericType> */
    private function expand(NumericType|ArrayType $value, int $length): array
    {
        $items = $value instanceof ArrayType ? $value->items : [$value];
        if ($items === [] && $length !== 0) {
            throw new \InvalidArgumentException('A repeated column dimension requires at least one number.');
        }
        foreach ($items as $item) {
            if (!$item instanceof NumericType || $item->value < 0) {
                throw new \InvalidArgumentException('Column dimensions must be non-negative numbers.');
            }
        }
        $result = [];
        for ($index = 0; $index < $length; ++$index) {
            $item = $items[min($index, count($items) - 1)];
            $result[] = $item;
        }
        return $result;
    }
}
