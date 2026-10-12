<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;

/** Positive sample-grid sizes, in input-dimension order (table 39). */
final class SamplingSize extends ArrayType
{
    /** @var non-empty-list<IntegerType> */
    public readonly array $sizes;
    private readonly int $gridSampleCount;

    public function __construct(IntegerType ...$sizes)
    {
        if ($sizes === []) {
            throw new \InvalidArgumentException('A sampling grid needs at least one dimension.');
        }
        $count = 1;
        foreach ($sizes as $size) {
            if (!is_int($size->value) || $size->value <= 0) {
                throw new \InvalidArgumentException('Every sampling-grid size must be a positive integer.');
            }
            if ($count > intdiv(PHP_INT_MAX, $size->value)) {
                throw new \InvalidArgumentException('The sampling-grid product exceeds the platform integer range.');
            }
            $count *= $size->value;
        }
        $this->sizes = array_values($sizes);
        $this->gridSampleCount = $count;
        parent::__construct(...$sizes);
    }

    public function dimensions(): int
    {
        return count($this->sizes);
    }

    public function sampleCount(): int
    {
        return $this->gridSampleCount;
    }
}
