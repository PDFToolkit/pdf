<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

/** PDF dictionary null is absence; explicit references retain their object targets. */
final readonly class DictionaryValues
{
    public function value(DictionaryType $dictionary, string $key): ?BasicType
    {
        $value = $dictionary->get($key);
        $value = $value instanceof IndirectReference ? $value->target() : $value;
        return $value instanceof NullType ? null : $value;
    }

    /** @return list<int|float>|null */
    public function numbers(DictionaryType $dictionary, string $key): ?array
    {
        $value = $this->value($dictionary, $key);
        if ($value === null) {
            return null;
        }
        if (!$value instanceof ArrayType) {
            throw new \InvalidArgumentException('/' . $key . ' requires an array of numbers.');
        }
        $numbers = [];
        foreach ($value->items as $item) {
            $item = $item instanceof IndirectReference ? $item->target() : $item;
            if (!$item instanceof NumericType) {
                throw new \InvalidArgumentException('/' . $key . ' requires numeric array members.');
            }
            $numbers[] = $item->value;
        }
        return $numbers;
    }

    /** @return list<string> */
    public function names(DictionaryType $dictionary, string $key): array
    {
        $value = $this->value($dictionary, $key);
        if ($value === null) {
            return [];
        }
        $items = $value instanceof ArrayType ? $value->items : [$value];
        $names = [];
        foreach ($items as $item) {
            $item = $item instanceof IndirectReference ? $item->target() : $item;
            if (!$item instanceof NameType) {
                throw new \InvalidArgumentException('/' . $key . ' requires names.');
            }
            $names[] = $item->value;
        }
        return $names;
    }
}
