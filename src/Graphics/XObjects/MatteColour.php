<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Syntax\Object\NumericType;

/** Explicit PDF preblend/unblend arithmetic in actual component values (11.6.5.3). */
final readonly class MatteColour
{
    public function __construct(public ColourComponents $colour)
    {
        $ranges = $colour->space->ranges();
        if ($ranges === null) {
            throw new \InvalidArgumentException('A matte needs a colour space with component ranges.');
        }
        foreach ($colour->numbers as $index => $number) {
            if ($number->value < $ranges->intervals[$index]->minimum->value || $number->value > $ranges->intervals[$index]->maximum->value) {
                throw new \InvalidArgumentException('Matte values must be valid components in their declared colour space.');
            }
        }
    }

    public function preblend(ColourComponents $source, NumericType $alpha): ColourComponents
    {
        $this->validate($source, $alpha);
        $numbers = [];
        foreach ($source->numbers as $index => $number) {
            $numbers[] = new NumericType($alpha->value * $number->value + (1 - $alpha->value) * $this->colour->numbers[$index]->value);
        }
        return new ColourComponents($source->space, ...$numbers);
    }

    public function unblend(ColourComponents $preblended, NumericType $alpha): ColourComponents
    {
        $this->validate($preblended, $alpha);
        if ($alpha->value == 0) {
            throw new \LogicException('The original colour cannot be recovered from a fully transparent preblended sample.');
        }
        $numbers = [];
        foreach ($preblended->numbers as $index => $number) {
            $numbers[] = new NumericType(($number->value - (1 - $alpha->value) * $this->colour->numbers[$index]->value) / $alpha->value);
        }
        return (new ColourComponents($preblended->space, ...$numbers))->clipped();
    }

    private function validate(ColourComponents $source, NumericType $alpha): void
    {
        if ($source->space !== $this->colour->space || $alpha->value < 0 || $alpha->value > 1) {
            throw new \InvalidArgumentException('Preblending needs matching explicit colour spaces and unit-range alpha.');
        }
        if ($source->space->family() === ColourSpaceFamily::Indexed) {
            throw new \InvalidArgumentException('Indexed preblending operates on the lookup-table colours, not index samples.');
        }
    }
}
