<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** Component values preserve their original numbers; clipping is an explicit operation (8.6.2). */
final class ColourComponents extends ArrayType
{
    /** @var list<NumericType> */
    public readonly array $numbers;

    public function __construct(public readonly ColourSpace $space, NumericType ...$numbers)
    {
        if (count($numbers) !== $space->componentCount()) {
            throw new \InvalidArgumentException('A colour needs exactly one number per colour-space component.');
        }
        $this->numbers = array_values($numbers);
        parent::__construct(...$numbers);
    }

    public function clipped(): self
    {
        $ranges = $this->space->ranges();
        $intervals = $ranges === null ? [] : $ranges->intervals;
        $values = [];
        foreach ($this->numbers as $index => $number) {
            $interval = $intervals[$index];
            $values[] = new NumericType(max($interval->minimum->value, min($interval->maximum->value, $number->value)));
        }
        return new self($this->space, ...$values);
    }
}
