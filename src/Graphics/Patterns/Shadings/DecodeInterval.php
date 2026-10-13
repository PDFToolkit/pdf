<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Functions\NumberInterval;
use PDF\Syntax\Object\NumericType;

/** Direction-preserving Decode endpoints, including reversed and constant intervals (8.9.5.2). */
final readonly class DecodeInterval
{
    public function __construct(public NumericType $first, public NumericType $last)
    {
    }

    public function domain(): NumberInterval
    {
        return new NumberInterval(new NumericType(min($this->first->value, $this->last->value)), new NumericType(max($this->first->value, $this->last->value)));
    }

    public function encode(NumericType $number, int $bits): int
    {
        $minimum = min($this->first->value, $this->last->value);
        $maximum = max($this->first->value, $this->last->value);
        if ($number->value < $minimum || $number->value > $maximum) {
            throw new \InvalidArgumentException('A mesh sample falls outside its explicitly declared Decode endpoints.');
        }
        if ($bits < 1 || $bits > 32 || ($bits === 32 && PHP_INT_SIZE < 8)) {
            throw new \InvalidArgumentException('Mesh integer quantization requires 1–32 bits and a 64-bit PHP integer for 32-bit coordinates.');
        }
        if ($minimum == $maximum) {
            return 0;
        }
        // Normalize before subtracting: two finite opposite endpoints can have
        // an infinite difference even though every quantized sample is finite.
        $scale = max(abs($this->first->value), abs($this->last->value));
        $ratio = ($number->value / $scale - $this->first->value / $scale) / ($this->last->value / $scale - $this->first->value / $scale);
        return (int) round($ratio * ((1 << $bits) - 1), 0, PHP_ROUND_HALF_UP);
    }

    public function decode(int $sample, int $bits): NumericType
    {
        if ($bits < 1 || $bits > 32 || ($bits === 32 && PHP_INT_SIZE < 8) || $sample < 0 || $sample > (1 << $bits) - 1) {
            throw new \InvalidArgumentException('A mesh integer sample must fit its declared bit width.');
        }
        $position = $sample / ((1 << $bits) - 1);
        return new NumericType((1 - $position) * $this->first->value + $position * $this->last->value);
    }
}
