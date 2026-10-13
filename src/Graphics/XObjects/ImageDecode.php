<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Graphics\Patterns\Shadings\DecodeInterval;
use PDF\Syntax\Object\ArrayType;

/** Direction-preserving component Decode endpoints (8.9.5.2, pp.217-219). */
final class ImageDecode extends ArrayType
{
    /** @var non-empty-list<DecodeInterval> */
    public readonly array $intervals;

    public function __construct(DecodeInterval ...$intervals)
    {
        if ($intervals === []) {
            throw new \InvalidArgumentException('Image Decode needs at least one component interval.');
        }
        $this->intervals = array_values($intervals);
        $numbers = [];
        foreach ($intervals as $interval) {
            array_push($numbers, $interval->first, $interval->last);
        }
        parent::__construct(...$numbers);
    }
}
