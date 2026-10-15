<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Specification\NameAlternatives;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

final readonly class NameEntry implements DictionaryConstraint
{
    private NameAlternatives $names;

    /** @param non-empty-list<string> $allowed */
    public function __construct(public string $key, array $allowed)
    {
        if ($key === '' || $allowed === []) {
            throw new \InvalidArgumentException('A dictionary name constraint requires a key and at least one name.');
        }
        $names = array_map(fn (string $name): NameType => new NameType($name), $allowed);
        $this->names = new NameAlternatives(array_shift($names), ...$names);
    }

    public function validate(DictionaryType $dictionary): void
    {
        $value = (new DictionaryValues())->value($dictionary, $this->key);
        if ($value !== null) {
            $this->names->validate($value);
        }
    }
}
