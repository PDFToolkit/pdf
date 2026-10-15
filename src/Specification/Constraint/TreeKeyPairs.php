<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;

/** Tables36/37 pp.135/137, EC3 #307: local ordered key/value pairs, never null keys. */
final readonly class TreeKeyPairs implements DictionaryConstraint
{
    public function __construct(public bool $numberKeys = false)
    {
    }

    public function validate(DictionaryType $dictionary): void
    {
        $key = $this->numberKeys ? 'Nums' : 'Names';
        $pairs = (new DictionaryValues())->value($dictionary, $key);
        if ($pairs === null) {
            return;
        }
        if (!$pairs instanceof ArrayType || count($pairs) % 2 !== 0) {
            throw new \InvalidArgumentException('/' . $key . ' requires complete key/value pairs.');
        }
        $previous = null;
        for ($index = 0; $index < count($pairs); $index += 2) {
            $item = $pairs->items[$index];
            $item = $item instanceof IndirectReference ? $item->target() : $item;
            if ((!$item instanceof IntegerType && !$item instanceof StringType)
                || ($this->numberKeys && !$item instanceof IntegerType)
                || (!$this->numberKeys && !$item instanceof StringType)) {
                throw new \InvalidArgumentException('/' . $key . ' keys require ' . ($this->numberKeys ? 'integers' : 'strings') . ', never null.');
            }
            $value = $item->value;
            if ($previous !== null && ($this->numberKeys ? $value <= $previous : strcmp((string) $value, (string) $previous) <= 0)) {
                throw new \InvalidArgumentException('/' . $key . ' keys must be strictly increasing without duplicates.');
            }
            $previous = $value;
        }
    }
}
