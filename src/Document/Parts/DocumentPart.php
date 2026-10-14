<?php

declare(strict_types=1);

namespace PDF\Document\Parts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Table409/physical pp.851–852: explicit document-scoped hierarchy builder, one parent and one page range. */
final class DocumentPart extends DictionaryType implements VersionedComponent
{
    private DocumentPart|DocumentPartRoot|null $parentPart = null;
    /** @var list<non-empty-list<DocumentPart>> */
    private array $groups = [];
    /** @var non-empty-list<PageObject>|null */
    private ?array $pages = null;

    public function __construct(?DocumentPartMetadata $metadata = null, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, ['Type', 'Parent', 'DParts', 'Start', 'End', 'DPM'], true)) {
                throw new \InvalidArgumentException('Document-part structural entries are managed by its explicit hierarchy.');
            }
        }
        parent::__construct(...($metadata === null ? [] : [new Entry(new NameType('DPM'), $metadata)]), ...$entries);
    }

    public function addGroup(self $first, self ...$others): self
    {
        if ($this->pages !== null) {
            throw new \LogicException('A document-part page leaf cannot acquire child groups.');
        }
        $group = [$first, ...array_values($others)];
        $seen = new \SplObjectStorage();
        foreach ($group as $child) {
            if ($child->parentPart !== null || $seen->offsetExists($child)) {
                throw new \InvalidArgumentException('A document-part group requires distinct unattached children.');
            }
            $seen->offsetSet($child);
            for ($node = $this; $node instanceof self; $node = $node->parentPart) {
                if ($node === $child) {
                    throw new \InvalidArgumentException('A document-part hierarchy cannot contain cycles.');
                }
            }
        }
        foreach ($group as $child) {
            $child->attachTo($this);
        }
        $this->groups[] = $group;
        return $this;
    }

    public function setPageRange(PageObject $first, PageObject ...$others): self
    {
        if ($this->groups !== [] || $this->pages !== null) {
            throw new \LogicException('A document part has either child groups or one explicitly assigned page range.');
        }
        foreach ([$first, ...$others] as $page) {
            $part = $page->get('DPart');
            if (!$part instanceof IndirectReference || $part->target() !== $this) {
                throw new \InvalidArgumentException('Each page in a part range requires its reciprocal DPart reference.');
            }
        }
        $this->pages = [$first, ...array_values($others)];
        return $this;
    }

    /** @internal Called only by the owning parent constructor or addGroup(). */
    public function attachTo(self|DocumentPartRoot $parent): void
    {
        if ($this->parentPart !== null) {
            throw new \InvalidArgumentException('A document part can belong to only one parent.');
        }
        for ($node = $parent; $node instanceof self; $node = $node->parentPart) {
            if ($node === $this) {
                throw new \InvalidArgumentException('A document-part hierarchy cannot contain cycles.');
            }
        }
        $this->parentPart = $parent;
    }

    public function entries(): iterable
    {
        if ($this->parentPart === null) {
            throw new \LogicException('Attach a document part to its document-part root before serialization.');
        }
        yield new Entry(new NameType('Type'), new NameType('DPart'));
        yield new Entry(new NameType('Parent'), new IndirectReference($this->parentPart));
        if ($this->groups !== []) {
            yield new Entry(new NameType('DParts'), new ArrayType(...array_map(static fn (array $group): ArrayType => new ArrayType(...array_map(static fn (self $child): IndirectReference => new IndirectReference($child), $group)), $this->groups)));
        }
        if ($this->pages !== null) {
            yield new Entry(new NameType('Start'), new IndirectReference($this->pages[0]));
            if (count($this->pages) > 1) {
                yield new Entry(new NameType('End'), new IndirectReference($this->pages[array_key_last($this->pages)]));
            }
        }
        yield from parent::entries();
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'Document parts');
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('A document part is an explicit hierarchy builder; supply metadata in its constructor.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Document-part structural entries cannot be removed independently.');
    }
    private function __clone(): void
    {
    }
}
