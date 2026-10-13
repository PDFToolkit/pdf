<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\BeadDictionaryTable161Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** One article region; only its explicit thread/page relationships are mutable. */
final class ArticleBead extends SchemaDictionary implements VersionedComponent
{
    private ?ArticleThread $thread = null;
    private ?PageObject $page = null;

    public function __construct(Rectangle $rectangle, public readonly bool $includeThread = false)
    {
        parent::__construct(new BeadDictionaryTable161Definition(), new Entry(new NameType('Type'), new NameType('Bead')), new Entry(new NameType('R'), $rectangle));
    }

    /** @internal Attach through ArticleThread::addBead(). */
    public function attachThread(ArticleThread $thread): void
    {
        if ($this->thread !== null) {
            throw new \InvalidArgumentException('An article bead belongs to one thread.');
        }
        $this->thread = $thread;
    }

    public function bindToPage(PageObject $page): self
    {
        $this->checkPageBinding($page);
        $this->page = $page;
        return $this;
    }

    /** Checks first so PageBeads can bind all its members atomically. */
    public function checkPageBinding(PageObject $page): void
    {
        if ($this->page !== null) {
            throw new \InvalidArgumentException('An article bead belongs to one explicit page.');
        }
        $beads = $page->get('B');
        $beads = $beads instanceof IndirectReference ? $beads->target() : $beads;
        $listed = false;
        foreach ($beads instanceof ArrayType ? $beads->items : [] as $ref) {
            if ($ref instanceof IndirectReference && $ref->target() === $this) {
                $listed = true;
            }
        }
        if (!$listed) {
            throw new \InvalidArgumentException('An article bead must be an indirect member of its page B array.');
        }
    }

    public function thread(): ArticleThread
    {
        return $this->thread ?? throw new \LogicException('Attach the bead to its article thread.');
    }

    public function page(): PageObject
    {
        return $this->page ?? throw new \LogicException('Bind the bead to its page after supplying page B.');
    }

    public function entries(): iterable
    {
        $thread = $this->thread();
        $beads = $thread->beads();
        $index = array_search($this, $beads, true);
        if ($index === false) {
            throw new \LogicException('The bead must be contained by its owning thread.');
        }
        if ($index === 0 || $this->includeThread) {
            yield new Entry(new NameType('T'), new IndirectReference($thread));
        }
        yield new Entry(new NameType('N'), new IndirectReference($beads[($index + 1) % count($beads)]));
        yield new Entry(new NameType('V'), new IndirectReference($beads[($index + count($beads) - 1) % count($beads)]));
        yield new Entry(new NameType('P'), new IndirectReference($this->page()));
        yield from parent::entries();
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $this->thread()->validateCatalog($catalog);
        $page = $this->page();
        (new CatalogPages($catalog))->index($page);
        $memberships = 0;
        foreach ((new CatalogPages($catalog))->pages as $candidate) {
            $beads = $candidate->get('B');
            $beads = $beads instanceof IndirectReference ? $beads->target() : $beads;
            if ($beads instanceof ArrayType) {
                foreach ($beads->items as $ref) {
                    if ($ref instanceof IndirectReference && $ref->target() === $this) {
                        ++$memberships;
                        if ($candidate !== $page) {
                            throw new \InvalidArgumentException('A bead P must match every actual page B association.');
                        }
                    }
                }
            }
        }
        if ($memberships !== 1) {
            throw new \InvalidArgumentException('An article bead must occur exactly once in its owning page B array.');
        }
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF11, 'Article beads');
        if ($this->includeThread && $this->thread()->beads()[0] !== $this) {
            $version->require(PdfVersion::PDF12, 'Thread T on a noninitial bead');
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Article bead structure is derived from its thread and page.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Article bead structure is derived from its thread and page.');
    }

    private function __clone(): void
    {
    }
}
