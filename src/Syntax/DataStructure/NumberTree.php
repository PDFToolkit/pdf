<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** A sorted number-tree leaf or branch (ISO 32000-1, 7.9.7). */
final class NumberTree extends DictionaryType
{
    /** @var list<NumberTreeEntry> */
    private array $pairs;
    /** @var list<self> */
    private array $children = [];
    private bool $isRoot = true;

    public function __construct(NumberTreeEntry ...$entries)
    {
        usort($entries, static fn (NumberTreeEntry $a, NumberTreeEntry $b): int => $a->key->value <=> $b->key->value);
        foreach ($entries as $index => $entry) {
            if ($index > 0 && $entries[$index - 1]->key->value === $entry->key->value) {
                throw new \InvalidArgumentException('A number-tree key must be unique.');
            }
        }
        $this->pairs = $entries;
        parent::__construct();
    }

    public static function branch(self ...$kids): self
    {
        if ($kids === []) {
            throw new \InvalidArgumentException('A number-tree branch needs at least one child.');
        }
        foreach ($kids as $kid) {
            if ($kid->firstKey() === null) {
                throw new \InvalidArgumentException('An empty number tree cannot be a branch child.');
            }
        }
        usort($kids, static fn (self $a, self $b): int => $a->requireFirstKey()->value <=> $b->requireFirstKey()->value);
        $tree = new self();
        foreach ($kids as $index => $kid) {
            if ($index > 0 && $kids[$index - 1]->requireLastKey()->value >= $kid->requireFirstKey()->value) {
                throw new \InvalidArgumentException('Number-tree child ranges must not overlap.');
            }
            $child = clone $kid;
            $child->isRoot = false;
            $tree->children[] = $child;
        }

        return $tree;
    }

    public function firstKey(): ?IntegerType
    {
        return $this->children !== [] ? $this->children[0]->firstKey() : ($this->pairs[0]->key ?? null);
    }

    public function lastKey(): ?IntegerType
    {
        return $this->children !== [] ? $this->children[array_key_last($this->children)]->lastKey() : ($this->pairs[array_key_last($this->pairs) ?? 0]->key ?? null);
    }

    public function entries(): iterable
    {
        if ($this->children !== []) {
            yield new Entry(new NameType('Kids'), new ArrayType(...array_map(static fn (self $child): IndirectReference => new IndirectReference($child), $this->children)));
        } else {
            $values = [];
            foreach ($this->pairs as $pair) {
                $values[] = $pair->key;
                $values[] = $pair->value;
            }
            yield new Entry(new NameType('Nums'), new ArrayType(...$values));
        }
        if (!$this->isRoot) {
            yield new Entry(new NameType('Limits'), new ArrayType($this->requireFirstKey(), $this->requireLastKey()));
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Number-tree entries and limits are derived from their typed pairs and children.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Number-tree entries and limits are derived from their typed pairs and children.');
    }

    private function requireFirstKey(): IntegerType
    {
        return $this->firstKey() ?? throw new \LogicException('An empty number tree has no first key.');
    }

    private function requireLastKey(): IntegerType
    {
        return $this->lastKey() ?? throw new \LogicException('An empty number tree has no last key.');
    }
}
