<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Specification\NameAlternatives;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** An unordered collection of allowed names; no preference or uniqueness is inferred. */
final readonly class NameArray implements DictionaryConstraint
{
    private NameAlternatives $names;

    /** @param non-empty-list<string> $allowed */
    public function __construct(public string $key, array $allowed)
    {
        if ($key === '' || $allowed === []) {
            throw new \InvalidArgumentException('A name-array constraint requires a key and allowed names.');
        }
        $names = array_map(fn (string $name): NameType => new NameType($name), $allowed);
        $this->names = new NameAlternatives(array_shift($names), ...$names);
    }

    public function validate(DictionaryType $dictionary): void
    {
        $value = (new DictionaryValues())->value($dictionary, $this->key);
        if ($value === null) {
            return;
        }
        if (!$value instanceof ArrayType) {
            throw new \InvalidArgumentException('/' . $this->key . ' requires an array of allowed names.');
        }
        foreach ($value->items as $item) {
            $this->names->validate($item instanceof IndirectReference ? $item->target() : $item);
        }
    }
}
