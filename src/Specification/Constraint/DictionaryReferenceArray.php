<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** A local array shape; document-wide target relationships require their own graph validator. */
final readonly class DictionaryReferenceArray implements DictionaryConstraint
{
    public function __construct(public string $key, public int $minimum = 0, public bool $nested = false)
    {
        if ($key === '' || $minimum < 0) {
            throw new \InvalidArgumentException('A reference-array constraint needs a key and a nonnegative minimum.');
        }
    }

    public function validate(DictionaryType $dictionary): void
    {
        $array = (new DictionaryValues())->value($dictionary, $this->key);
        if ($array === null) {
            return;
        }
        if (!$array instanceof ArrayType || count($array) < $this->minimum) {
            throw new \InvalidArgumentException('/' . $this->key . ' requires an array with at least ' . $this->minimum . ' elements.');
        }
        if (!$this->nested) {
            $this->references($array);
            return;
        }
        foreach ($array as $collection) {
            $collection = $collection instanceof IndirectReference ? $collection->target() : $collection;
            if (!$collection instanceof ArrayType || count($collection) === 0) {
                throw new \InvalidArgumentException('/' . $this->key . ' requires nonempty inner arrays (EC3 #225).');
            }
            $this->references($collection);
        }
    }

    private function references(ArrayType $array): void
    {
        foreach ($array as $value) {
            if (!$value instanceof IndirectReference || !$value->target() instanceof DictionaryType) {
                throw new \InvalidArgumentException('/' . $this->key . ' array items must be indirect dictionary references.');
            }
        }
    }
}
