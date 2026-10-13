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

    public function __construct(NameTreeEntry|NameTreeChildren ...$entries)
    {
        parent::__construct();
        if (count($entries) === 1 && $entries[0] instanceof NameTreeChildren) {
            $this->pairs = [];
            foreach ($entries[0]->trees as $kid) {
                $child = clone $kid;
                $child->isRoot = false;
                $this->children[] = $child;
            }
            return;
        }
        foreach ($entries as $entry) {
            if (!$entry instanceof NameTreeEntry) {
                throw new \InvalidArgumentException('A branch Kids collection cannot be mixed with leaf entries.');
            }
        }
        usort($entries, static fn (NameTreeEntry $a, NameTreeEntry $b): int => strcmp($a->key->value, $b->key->value));
        foreach ($entries as $index => $entry) {
            if ($index > 0 && $entries[$index - 1]->key->value === $entry->key->value) {
                throw new \InvalidArgumentException('A name-tree key must be unique.');
            }
        }
        $this->pairs = $entries;
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
