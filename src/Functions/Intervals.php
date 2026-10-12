<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Syntax\Object\ArrayType;

/** Flattened min/max pairs with an explicit dimension count. */
abstract class Intervals extends ArrayType
{
    /** @var non-empty-list<NumberInterval> */
    public readonly array $intervals;

    public function __construct(NumberInterval ...$intervals)
    {
        if ($intervals === []) {
            throw new \InvalidArgumentException('A function domain or range needs at least one interval.');
        }
        $this->intervals = array_values($intervals);
        $values = [];
        foreach ($intervals as $interval) {
            $values[] = $interval->minimum;
            $values[] = $interval->maximum;
        }
        parent::__construct(...$values);
    }

    public function dimensions(): int
    {
        return count($this->intervals);
    }
}
