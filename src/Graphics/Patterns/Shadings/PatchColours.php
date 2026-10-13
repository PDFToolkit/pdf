<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Functions\NumberArray;
use PDF\Syntax\Object\NumericType;

/** Four corner sample vectors in stream order: p00, p03, p33, p30 (pages 204/208). */
final readonly class PatchColours
{
    public function __construct(public NumberArray $first, public NumberArray $second, public NumberArray $third, public NumberArray $fourth)
    {
        if (count($first) === 0 || count($first) !== count($second) || count($first) !== count($third) || count($first) !== count($fourth)) {
            throw new \InvalidArgumentException('All patch corner sample vectors must have the same positive dimension.');
        }
    }

    /** @return list<NumberArray> */
    public function corners(): array
    {
        return [$this->first, $this->second, $this->third, $this->fourth];
    }

    public function at(NumericType $u, NumericType $v): NumberArray
    {
        if ($u->value < 0 || $u->value > 1 || $v->value < 0 || $v->value > 1) {
            throw new \InvalidArgumentException('Patch parameters must lie in the unit square.');
        }
        $values = [];
        foreach ($this->first->numbers as $index => $number) {
            $values[] = new NumericType((1 - $u->value) * (1 - $v->value) * $number->value + (1 - $u->value) * $v->value * $this->second->numbers[$index]->value + $u->value * $v->value * $this->third->numbers[$index]->value + $u->value * (1 - $v->value) * $this->fourth->numbers[$index]->value);
        }
        return new NumberArray(...$values);
    }
}
