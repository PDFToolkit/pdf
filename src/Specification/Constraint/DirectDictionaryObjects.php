<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\StreamObject;

/** Direct-only declaration graphs, including extension keys; EC3 #403, pp.157/899. */
final readonly class DirectDictionaryObjects implements DictionaryConstraint
{
    /** @param list<string> $prohibited */
    public function __construct(public array $prohibited = ['Metadata', 'AF'], public int $maximumDepth = 64, public int $maximumObjects = 100_000)
    {
        if ($maximumDepth < 1 || $maximumObjects < 1) {
            throw new \InvalidArgumentException('Direct-object validation limits must be positive.');
        }
    }

    public function validate(DictionaryType $dictionary): void
    {
        foreach ($this->prohibited as $key) {
            if ((new DictionaryValues())->value($dictionary, $key) !== null) {
                throw new \InvalidArgumentException('A direct-only dictionary forbids /' . $key . '.');
            }
        }
        /** @var \SplObjectStorage<BasicType, null> $active */
        $active = new \SplObjectStorage();
        /** @var \SplObjectStorage<BasicType, null> $checked */
        $checked = new \SplObjectStorage();
        $visited = 0;
        $this->visit($dictionary, 0, $visited, $active, $checked);
    }

    /**
     * @param \SplObjectStorage<BasicType, null> $active
     * @param \SplObjectStorage<BasicType, null> $checked
     */
    private function visit(BasicType $value, int $depth, int &$visited, \SplObjectStorage $active, \SplObjectStorage $checked): void
    {
        if (++$visited > $this->maximumObjects || $depth > $this->maximumDepth) {
            throw new \InvalidArgumentException('Direct-object validation exceeds its explicit limits.');
        }
        if ($value instanceof IndirectReference || $value instanceof StreamObject) {
            throw new \InvalidArgumentException('Every value in this dictionary and its containers shall be direct.');
        }
        if ($active->offsetExists($value)) {
            throw new \InvalidArgumentException('A direct-only dictionary cannot contain a cycle.');
        }
        if ($checked->offsetExists($value)) {
            return;
        }
        $active->offsetSet($value);
        try {
            if ($value instanceof ArrayType) {
                foreach ($value->items as $item) {
                    $this->visit($item, $depth + 1, $visited, $active, $checked);
                }
            } elseif ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    $this->visit($entry->value, $depth + 1, $visited, $active, $checked);
                }
            }
            $checked->offsetSet($value);
        } finally {
            $active->offsetUnset($value);
        }
    }
}
