<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\IntegerType;

final readonly class CIDVerticalRun
{
    public CIDRange $range;
    /** @var list<CIDVerticalMetric> */
    public array $metrics;
    public function __construct(IntegerType $first, CIDVerticalMetric ...$metrics)
    {
        if ($metrics === [] || $first->integer() > 65536 - count($metrics)) {
            throw new \InvalidArgumentException('A vertical metric run is nonempty and fits the CID domain.');
        }
        $this->range = new CIDRange($first, new IntegerType($first->integer() + count($metrics) - 1));
        $this->metrics = array_values($metrics);
    }
    public function metric(int $cid): CIDVerticalMetric
    {
        return $this->metrics[$cid - $this->range->first->integer()] ?? throw new \OutOfBoundsException('CID outside this vertical run.');
    }
}
