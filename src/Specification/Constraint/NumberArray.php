<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\DictionaryType;

final readonly class NumberArray implements DictionaryConstraint
{
    /** @param list<int> $lengths */
    public function __construct(public string $key, public array $lengths = [], public int $minimumLength = 0, public bool $pairs = false, public ?float $minimum = null, public bool $exclusiveMinimum = false, public bool $integer = false, public bool $ascending = false, public ?float $maximum = null, public int $multipleOf = 1)
    {
        if ($key === '' || $minimumLength < 0 || $multipleOf < 1 || array_filter($lengths, fn (int $length): bool => $length < 0) !== []) {
            throw new \InvalidArgumentException('Invalid numeric array constraint.');
        }
    }

    public function validate(DictionaryType $dictionary): void
    {
        $numbers = (new DictionaryValues())->numbers($dictionary, $this->key);
        if ($numbers === null) {
            return;
        }
        $count = count($numbers);
        if ($count < $this->minimumLength || $count % $this->multipleOf !== 0 || ($this->lengths !== [] && !in_array($count, $this->lengths, true)) || ($this->pairs && $count % 2 !== 0)) {
            throw new \InvalidArgumentException('/' . $this->key . ' has an invalid array length.');
        }
        foreach ($numbers as $index => $number) {
            if (($this->integer && !is_int($number)) || ($this->maximum !== null && $number > $this->maximum) || ($this->minimum !== null && ($number < $this->minimum || ($this->exclusiveMinimum && $number == $this->minimum)))) {
                throw new \InvalidArgumentException('/' . $this->key . ' has a member outside its numeric domain.');
            }
            if ($this->pairs && $index % 2 === 1 && $numbers[$index - 1] > $number) {
                throw new \InvalidArgumentException('/' . $this->key . ' has a reversed interval.');
            }
            if ($this->ascending && $index > 0 && $numbers[$index - 1] >= $number) {
                throw new \InvalidArgumentException('/' . $this->key . ' shall be strictly increasing.');
            }
        }
    }
}
