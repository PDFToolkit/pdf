<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Document\Destinations\Destination;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Outline item; parent and sibling links are generated from its hierarchy. */
final class OutlineItem extends DictionaryType implements OutlineParent
{
    private ?OutlineParent $parentNode = null;
    /** @var list<self> */
    private array $children = [];

    public function __construct(
        TextString $title,
        Destination|DictionaryType|null $destinationOrAction = null,
        public readonly bool $expanded = true,
        Entry ...$entries,
    ) {
        foreach ($entries as $entry) {
            if (in_array($entry->key()->value, ['Title', 'Parent', 'Prev', 'Next', 'First', 'Last', 'Count', 'Dest', 'A'], true)) {
                throw new \InvalidArgumentException('Structural outline entries are supplied by the outline builder.');
            }
        }
        $required = [new Entry(new NameType('Title'), $title)];
        if ($destinationOrAction !== null) {
            $required[] = new Entry(new NameType($destinationOrAction instanceof Destination ? 'Dest' : 'A'), $destinationOrAction);
        }
        parent::__construct(...$required, ...$entries);
    }

    public function addItem(self $item): self
    {
        for ($node = $this; $node instanceof self; $node = $node->parentNode) {
            if ($node === $item) {
                throw new \InvalidArgumentException('An outline hierarchy cannot contain a cycle.');
            }
        }
        $item->attachTo($this);
        $this->children[] = $item;

        return $this;
    }

    /** @internal Attach through addItem(). */
    public function attachTo(OutlineParent $parent): void
    {
        if ($this->parentNode !== null || $parent === $this) {
            throw new \InvalidArgumentException('An outline item can only be attached once.');
        }
        for ($node = $parent; $node instanceof self; $node = $node->parentNode) {
            if ($node === $this) {
                throw new \InvalidArgumentException('An outline hierarchy cannot contain a cycle.');
            }
        }
        $this->parentNode = $parent;
    }

    public function items(): array
    {
        return $this->children;
    }

    public function visibleDescendantCount(): int
    {
        return array_sum(array_map(static fn (self $item): int => 1 + ($item->expanded ? $item->visibleDescendantCount() : 0), $this->children));
    }

    public function entries(): iterable
    {
        if ($this->parentNode === null) {
            throw new \LogicException('An outline item must be attached to an outline hierarchy.');
        }
        yield from parent::entries();
        yield new Entry(new NameType('Parent'), new IndirectReference($this->parentNode));
        $siblings = $this->parentNode->items();
        $index = array_search($this, $siblings, true);
        if ($index === false) {
            throw new \LogicException('An outline parent must contain its child.');
        }
        if ($index > 0) {
            yield new Entry(new NameType('Prev'), new IndirectReference($siblings[$index - 1]));
        }
        if (isset($siblings[$index + 1])) {
            yield new Entry(new NameType('Next'), new IndirectReference($siblings[$index + 1]));
        }
        if ($this->children !== []) {
            yield new Entry(new NameType('First'), new IndirectReference($this->children[0]));
            yield new Entry(new NameType('Last'), new IndirectReference($this->children[array_key_last($this->children)]));
            yield new Entry(new NameType('Count'), new IntegerType(($this->expanded ? 1 : -1) * $this->visibleDescendantCount()));
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('An outline item is a hierarchy builder; provide optional entries in its constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('An outline item is a hierarchy builder; its structural entries cannot be removed.');
    }

    private function __clone(): void
    {
    }
}
