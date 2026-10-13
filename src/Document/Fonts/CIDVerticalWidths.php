<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;

/** 9.7.4.3: both W2 forms, with checked CID domains and exact triples. */
final class CIDVerticalWidths extends ArrayType
{
    /** @var list<CIDVerticalRun|CIDVerticalRange> */
    public readonly array $runs;
    public function __construct(CIDVerticalRun|CIDVerticalRange ...$runs)
    {
        $items = [];
        new CIDRangeSet(...array_map(static fn (CIDVerticalRun|CIDVerticalRange $run): CIDRange => $run->range, array_values($runs)));
        foreach ($runs as $run) {
            $items[] = $run->range->first;
            if ($run instanceof CIDVerticalRun) {
                $triples = [];
                foreach ($run->metrics as $metric) {
                    array_push($triples, ...$metric->operands());
                }
                $items[] = new ArrayType(...$triples);
            } else {
                $items[] = $run->range->last;
                array_push($items, ...$run->value->operands());
            }
        }
        $this->runs = array_values($runs);
        parent::__construct(...$items);
    }
    public function metric(IntegerType $cid): ?CIDVerticalMetric
    {
        if ($cid->integer() < 0 || $cid->integer() > 65535) {
            throw new \InvalidArgumentException('CID outside 0–65535.');
        }
        foreach ($this->runs as $run) {
            if ($run->range->contains($cid->integer())) {
                return $run->metric($cid->integer());
            }
        }
        return null;
    }
}
