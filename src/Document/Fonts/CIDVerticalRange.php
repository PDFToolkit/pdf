<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

final readonly class CIDVerticalRange
{
    public function __construct(public CIDRange $range, public CIDVerticalMetric $value)
    {
    }
    public function metric(int $cid): CIDVerticalMetric
    {
        return $this->range->contains($cid) ? $this->value : throw new \OutOfBoundsException('CID outside this vertical range.');
    }
}
