<?php

declare(strict_types=1);

namespace PDF\Specification;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\NameType;

/** An explicit, closed set of case-sensitive PDF names from one reviewed source entry. */
final readonly class NameAlternatives
{
    /** @var non-empty-list<NameType> */
    public array $names;

    public function __construct(NameType $first, NameType ...$others)
    {
        $names = [$first, ...array_values($others)];
        $seen = [];
        foreach ($names as $name) {
            if (isset($seen[$name->value])) {
                throw new \InvalidArgumentException('PDF name alternatives must be distinct.');
            }
            $seen[$name->value] = true;
        }
        $this->names = $names;
    }

    public function validate(BasicType $value): void
    {
        if ($value instanceof NameType) {
            foreach ($this->names as $name) {
                if ($value->value === $name->value) {
                    return;
                }
            }
        }
        throw new \InvalidArgumentException('This dictionary entry requires one of its explicitly declared PDF names.');
    }
}
