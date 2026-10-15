<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\PageTree;

use PDF\Document\ResourceDictionary;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** A page dictionary with explicit media box, resources and optional contents. */
final class PageObject extends DictionaryType implements PageTree
{
    private ?PageTreeNode $parentNode = null;

    public function __construct(
        private readonly ?Rectangle $mediaBox = null,
        private readonly ResourceDictionary|IndirectReference|null $resources = null,
        StreamObject|IndirectReference|ArrayType|null $contents = null,
        Entry ...$entries,
    ) {
        foreach ($entries as $entry) {
            if (in_array($entry->key()->value, ['Type', 'Parent', 'MediaBox', 'Resources', 'Contents'], true)) {
                throw new \InvalidArgumentException('Required page entries are supplied by the constructor.');
            }
        }
        if ($resources instanceof IndirectReference && !$resources->target() instanceof ResourceDictionary) {
            throw new \InvalidArgumentException('Resources must reference a resource dictionary.');
        }
        if ($contents instanceof IndirectReference && !$contents->target() instanceof StreamObject) {
            throw new \InvalidArgumentException('Contents must reference a stream.');
        }
        if ($contents instanceof ArrayType) {
            if (count($contents) === 0) {
                throw new \InvalidArgumentException('A PDF writer shall not create an empty Contents array; omit Contents for an empty page.');
            }
            foreach ($contents->items as $item) {
                if (!$item instanceof IndirectReference || !$item->target() instanceof StreamObject) {
                    throw new \InvalidArgumentException('A Contents array must contain indirect stream references.');
                }
            }
        }
        $required = [
            new Entry(new NameType('Type'), new NameType('Page')),
        ];
        if ($mediaBox !== null) {
            $required[] = new Entry(new NameType('MediaBox'), $mediaBox);
        }
        if ($resources !== null) {
            $required[] = new Entry(new NameType('Resources'), $resources);
        }
        if ($contents !== null) {
            $required[] = new Entry(new NameType('Contents'), $contents instanceof StreamObject ? new IndirectReference($contents) : $contents);
        }
        parent::__construct(...$required, ...$entries);
    }

    public function parent(): ?PageTreeNode
    {
        return $this->parentNode;
    }

    public function attachTo(PageTreeNode $parent): void
    {
        if ($this->parentNode !== null) {
            throw new \InvalidArgumentException('A page can only be attached once.');
        }
        $this->parentNode = $parent;
    }

    public function pageCount(): int
    {
        return 1;
    }

    public function entries(): iterable
    {
        if ($this->parentNode === null) {
            throw new \LogicException('Attach a page to a page-tree node before serializing it.');
        }
        if (!in_array($this, $this->parentNode->kids(), true)) {
            throw new \LogicException('A page parent must contain its page in Kids.');
        }
        $this->effectiveMediaBox();
        $this->effectiveResources();
        yield new Entry(new NameType('Parent'), new IndirectReference($this->parentNode));
        yield from parent::entries();
    }

    public function with(Entry $entry): static
    {
        if (in_array($entry->key()->value, ['Type', 'Parent', 'MediaBox', 'Resources', 'Contents'], true)) {
            throw new \InvalidArgumentException('Required page entries cannot be replaced with generic entries.');
        }
        if ($this->parentNode !== null) {
            throw new \LogicException('Configure a page before attaching it to its page tree.');
        }

        return parent::with($entry);
    }

    public function without(NameType|string $key): static
    {
        if (in_array($key instanceof NameType ? $key->value : $key, ['Type', 'Parent', 'MediaBox', 'Resources', 'Contents'], true)) {
            throw new \InvalidArgumentException('Required page entries cannot be removed.');
        }
        if ($this->parentNode !== null) {
            throw new \LogicException('Configure a page before attaching it to its page tree.');
        }

        return parent::without($key);
    }

    public function effectiveMediaBox(): Rectangle
    {
        if ($this->mediaBox !== null) {
            return $this->mediaBox;
        }
        for ($node = $this->parentNode; $node !== null; $node = $node->parent()) {
            $value = $node->get('MediaBox');
            if ($value !== null) {
                if (!$value instanceof Rectangle) {
                    throw new \LogicException('An inherited MediaBox must be a rectangle.');
                }

                return $value;
            }
        }
        throw new \LogicException('A page needs an explicit or inherited MediaBox.');
    }

    public function effectiveResources(): ResourceDictionary|IndirectReference
    {
        if ($this->resources !== null) {
            return $this->resources;
        }
        for ($node = $this->parentNode; $node !== null; $node = $node->parent()) {
            $value = $node->get('Resources');
            if ($value !== null) {
                $target = $value instanceof IndirectReference ? $value->target() : $value;
                if (!$target instanceof ResourceDictionary) {
                    throw new \LogicException('Inherited Resources must resolve to a resource dictionary.');
                }

                return $value instanceof IndirectReference ? $value : $target;
            }
        }
        throw new \LogicException('A page needs explicit or inherited Resources.');
    }

    public function __clone(): void
    {
        if ($this->parentNode !== null) {
            throw new \LogicException('An attached page cannot be cloned.');
        }
    }
}
