<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

/** Sorted, nonoverlapping intervals; validation does not expand or quadratically compare CID ranges. */
final readonly class CIDRangeSet
{
    /** @var list<CIDRange> */
    public array $ranges;

    public function __construct(CIDRange ...$ranges)
    {
        $ordered = array_values($ranges);
        usort($ordered, static fn (CIDRange $left, CIDRange $right): int => $left->first->integer() <=> $right->first->integer());
        $previous = null;
        foreach ($ordered as $range) {
            if ($previous !== null && $previous->overlaps($range)) {
                throw new \InvalidArgumentException('CID metric ranges cannot overlap.');
            }
            $previous = $range;
        }
        $this->ranges = $ordered;
    }
}
