<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** A sorted name-tree leaf or branch with indirect kids and computed limits. */
final class NameTree extends DictionaryType
{
    /** @var list<NameTreeEntry> */
    private array $pairs;
    /** @var list<self> */
    private array $children = [];
    private bool $isRoot = true;

    public function __construct(NameTreeEntry ...$entries)
    {
        usort($entries, static fn (NameTreeEntry $a, NameTreeEntry $b): int => strcmp($a->key->value, $b->key->value));
        foreach ($entries as $index => $entry) {
            if ($index > 0 && $entries[$index - 1]->key->value === $entry->key->value) {
                throw new \InvalidArgumentException('A name-tree key must be unique.');
            }
        }
        $this->pairs = $entries;
        parent::__construct();
    }

    public static function branch(self ...$kids): self
    {
        if ($kids === []) {
            throw new \InvalidArgumentException('A name-tree branch needs at least one child.');
        }
        foreach ($kids as $kid) {
            if ($kid->firstKey() === null) {
                throw new \InvalidArgumentException('An empty name tree cannot be a branch child.');
            }
        }
        usort($kids, static fn (self $a, self $b): int => strcmp($a->requireFirstKey()->value, $b->requireFirstKey()->value));
        $tree = new self();
        foreach ($kids as $index => $kid) {
            if ($index > 0 && strcmp($kids[$index - 1]->requireLastKey()->value, $kid->requireFirstKey()->value) >= 0) {
                throw new \InvalidArgumentException('Name-tree child ranges must not overlap.');
            }
            $child = clone $kid;
            $child->isRoot = false;
            $tree->children[] = $child;
        }

        return $tree;
    }

    public function firstKey(): ?StringType
    {
        return $this->children !== [] ? $this->children[0]->firstKey() : ($this->pairs[0]->key ?? null);
    }

    public function lastKey(): ?StringType
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
            yield new Entry(new NameType('Names'), new ArrayType(...$values));
        }
        if (!$this->isRoot) {
            yield new Entry(new NameType('Limits'), new ArrayType($this->requireFirstKey(), $this->requireLastKey()));
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Name-tree entries and limits are derived from their typed pairs and children.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Name-tree entries and limits are derived from their typed pairs and children.');
    }

    private function requireFirstKey(): StringType
    {
        return $this->firstKey() ?? throw new \LogicException('An empty name tree has no first key.');
    }

    private function requireLastKey(): StringType
    {
        return $this->lastKey() ?? throw new \LogicException('An empty name tree has no last key.');
    }
}
