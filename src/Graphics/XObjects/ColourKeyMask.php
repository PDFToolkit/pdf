<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Functions\NumberInterval;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;

/** Inclusive raw-sample intervals, before Decode (8.9.6.4, p.222). */
final class ColourKeyMask extends ArrayType
{
    /** @var non-empty-list<NumberInterval> */
    public readonly array $intervals;

    public function __construct(NumberInterval ...$intervals)
    {
        if ($intervals === []) {
            throw new \InvalidArgumentException('A colour-key mask needs every component interval.');
        }
        $this->intervals = array_values($intervals);
        $values = [];
        foreach ($intervals as $interval) {
            if (!$interval->minimum instanceof IntegerType || !$interval->maximum instanceof IntegerType || $interval->minimum->integer() < 0) {
                throw new \InvalidArgumentException('Colour-key endpoints must be nonnegative integer sample values.');
            }
            array_push($values, $interval->minimum, $interval->maximum);
        }
        parent::__construct(...$values);
    }

    public function validate(int $components, int $bits): void
    {
        if (count($this->intervals) !== $components) {
            throw new \InvalidArgumentException('A colour-key mask needs one range per image component.');
        }
        foreach ($this->intervals as $interval) {
            if ($interval->maximum->value > (1 << $bits) - 1) {
                throw new \InvalidArgumentException('Colour-key endpoints must fit BitsPerComponent.');
            }
        }
    }

    public function masks(int ...$components): bool
    {
        if (count($components) !== count($this->intervals)) {
            throw new \InvalidArgumentException('Colour-key decisions require every raw sample component.');
        }
        foreach (array_values($components) as $index => $value) {
            if ($value < $this->intervals[$index]->minimum->value || $value > $this->intervals[$index]->maximum->value) {
                return false;
            }
        }
        return true;
    }
}
