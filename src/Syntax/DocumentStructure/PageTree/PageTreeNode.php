<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\PageTree;

use PDF\Syntax\DocumentStructure\PageTree;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Mutable tree builder; children can belong to exactly one parent. */
final class PageTreeNode extends DictionaryType implements PageTree
{
    private ?self $parentNode = null;

    /** @var list<PageObject|self> */
    private array $children = [];

    public function __construct(Entry ...$inheritableEntries)
    {
        foreach ($inheritableEntries as $entry) {
            if (in_array($entry->key()->value, ['Type', 'Parent', 'Kids', 'Count'], true)) {
                throw new \InvalidArgumentException('Page-tree structural entries are managed by the tree.');
            }
        }
        parent::__construct(...$inheritableEntries);
    }

    public function addKid(PageObject|self $kid): self
    {
        for ($node = $this; $node !== null; $node = $node->parent()) {
            if ($node === $kid) {
                throw new \InvalidArgumentException('A page tree cannot contain a cycle.');
            }
        }
        if ($kid->parent() !== null) {
            throw new \InvalidArgumentException('The child already belongs to a page-tree node.');
        }
        $kid->attachTo($this);
        $this->children[] = $kid;

        return $this;
    }

    public function parent(): ?self
    {
        return $this->parentNode;
    }

    public function attachTo(self $parent): void
    {
        if ($this->parentNode !== null || $parent === $this) {
            throw new \InvalidArgumentException('A page-tree node can only be attached once.');
        }
        for ($node = $parent; $node !== null; $node = $node->parent()) {
            if ($node === $this) {
                throw new \InvalidArgumentException('A page tree cannot contain a cycle.');
            }
        }
        $this->parentNode = $parent;
    }

    /** @return list<PageObject|self> */
    public function kids(): array
    {
        return $this->children;
    }

    public function pageCount(): int
    {
        return array_sum(array_map(static fn (PageTree $kid): int => $kid->pageCount(), $this->children));
    }

    public function entries(): iterable
    {
        if ($this->parentNode !== null && !in_array($this, $this->parentNode->kids(), true)) {
            throw new \LogicException('A page-tree parent must contain its child in Kids.');
        }
        yield new Entry(new NameType('Type'), new NameType('Pages'));
        if ($this->parentNode !== null) {
            yield new Entry(new NameType('Parent'), new IndirectReference($this->parentNode));
        }
        yield new Entry(new NameType('Kids'), new ArrayType(...array_map(
            static fn (PageTree $kid): IndirectReference => new IndirectReference($kid),
            $this->children,
        )));
        yield new Entry(new NameType('Count'), new IntegerType($this->pageCount()));
        yield from parent::entries();
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('A page tree is a mutable builder; provide inheritable entries in its constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('A page tree is a mutable builder; its structural entries cannot be removed.');
    }

    private function __clone(): void
    {
    }
}
