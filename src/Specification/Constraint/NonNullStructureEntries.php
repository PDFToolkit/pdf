<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NullType;

/** EC3 #308, pp.738/740–741: designated structure values and members cannot be null. */
final readonly class NonNullStructureEntries implements DictionaryConstraint
{
    /** @param list<string> $keys */
    public function __construct(public array $keys)
    {
    }

    public function validate(DictionaryType $dictionary): void
    {
        foreach ($this->keys as $key) {
            $value = $dictionary->get($key);
            if ($value === null) {
                continue;
            }
            $value = $value instanceof IndirectReference ? $value->target() : $value;
            foreach ($value instanceof ArrayType ? $value->items : [$value] as $item) {
                $item = $item instanceof IndirectReference ? $item->target() : $item;
                if ($item instanceof NullType) {
                    throw new \InvalidArgumentException('Structure /' . $key . ' values and array members shall not be null.');
                }
            }
        }
    }
}
