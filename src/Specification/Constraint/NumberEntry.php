<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

final readonly class NumberEntry implements DictionaryConstraint
{
    /** @param list<int|float> $allowed */
    public function __construct(public string $key, public ?float $minimum = null, public ?float $maximum = null, public bool $exclusiveMinimum = false, public bool $nonzero = false, public bool $integer = false, public array $allowed = [])
    {
        if ($key === '' || ($minimum !== null && $maximum !== null && $minimum > $maximum)) {
            throw new \InvalidArgumentException('Invalid numeric dictionary constraint.');
        }
    }

    public function validate(DictionaryType $dictionary): void
    {
        $value = (new DictionaryValues())->value($dictionary, $this->key);
        if ($value === null) {
            return;
        }
        if (!$value instanceof NumericType || ($this->integer && !$value instanceof IntegerType)) {
            throw new \InvalidArgumentException('/' . $this->key . ' requires ' . ($this->integer ? 'an integer.' : 'a number.'));
        }
        $number = $value->value;
        if (($this->minimum !== null && ($number < $this->minimum || ($this->exclusiveMinimum && $number == $this->minimum)))
            || ($this->maximum !== null && $number > $this->maximum)
            || ($this->nonzero && $number == 0)
            || ($this->allowed !== [] && !in_array($number, $this->allowed, false))) {
            throw new \InvalidArgumentException('/' . $this->key . ' is outside its specified numeric domain.');
        }
    }
}
