<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Immutable drawing order, distinct from thread order; page relationships bind once. */
final class PageBeads extends ArrayType
{
    /** @var list<ArticleBead> */
    public readonly array $beads;
    private ?PageObject $page = null;

    public function __construct(ArticleBead ...$beads)
    {
        $seen = new \SplObjectStorage();
        foreach ($beads as $bead) {
            if ($seen->offsetExists($bead)) {
                throw new \InvalidArgumentException('A page drawing order cannot repeat the same bead.');
            }
            $seen->offsetSet($bead);
        }
        $this->beads = array_values($beads);
        parent::__construct(...array_map(static fn (ArticleBead $bead): IndirectReference => new IndirectReference($bead), $beads));
    }

    public function pageEntry(): Entry
    {
        return new Entry(new NameType('B'), $this);
    }

    public function bindToPage(PageObject $page): self
    {
        if ($this->page !== null) {
            throw new \InvalidArgumentException('A page bead collection is bound once.');
        }
        $value = $page->get('B');
        $value = $value instanceof IndirectReference ? $value->target() : $value;
        if ($value !== $this) {
            throw new \InvalidArgumentException('Supply this PageBeads as the actual page B before binding.');
        }
        foreach ($this->beads as $bead) {
            $bead->checkPageBinding($page);
        }
        foreach ($this->beads as $bead) {
            $bead->bindToPage($page);
        }
        $this->page = $page;
        return $this;
    }

    private function __clone(): void
    {
    }
}
