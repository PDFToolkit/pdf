<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Root of an outline hierarchy with linked, indirectly referenced items. */
final class OutlineDictionary extends DictionaryType implements OutlineParent
{
    /** @var list<OutlineItem> */
    private array $children = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function addItem(OutlineItem $item): self
    {
        $item->attachTo($this);
        $this->children[] = $item;

        return $this;
    }

    public function items(): array
    {
        return $this->children;
    }

    public function entries(): iterable
    {
        yield new Entry(new NameType('Type'), new NameType('Outlines'));
        if ($this->children !== []) {
            yield new Entry(new NameType('First'), new IndirectReference($this->children[0]));
            yield new Entry(new NameType('Last'), new IndirectReference($this->children[array_key_last($this->children)]));
            // PDF32000_2008.pdf Table 152, physical p.376, and pdf_17_errata.pdf p.10:
            // visible top-level items alone do not require Count when all are closed.
            if (array_filter($this->children, static fn (OutlineItem $item): bool => $item->expanded) !== []) {
                yield new Entry(new NameType('Count'), new IntegerType(array_sum(array_map(static fn (OutlineItem $item): int => 1 + ($item->expanded ? $item->visibleDescendantCount() : 0), $this->children))));
            }
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('An outline dictionary is configured through its outline items.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('An outline dictionary is configured through its outline items.');
    }

    private function __clone(): void
    {
    }
}
