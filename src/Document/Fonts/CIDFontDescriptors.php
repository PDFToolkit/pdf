<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\Fonts\CMaps\CMapSystemInfo;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Table 124 FD graph, with Table 125 glyph classes checked against the declared collection. */
final class CIDFontDescriptors extends DictionaryType
{
    public function __construct(public readonly CMapSystemInfo $systemInfo, CIDClassDescriptor ...$classes)
    {
        $entries = [];
        foreach ($classes as $class) {
            if ($systemInfo->registry->value !== 'Adobe' || !$class->class->supports($systemInfo->ordering->value)) {
                throw new \InvalidArgumentException('The glyph class belongs to its Adobe character collection.');
            }
            $entries[] = new Entry(new NameType($class->class->value), new IndirectReference($class->descriptor));
        }
        parent::__construct(...$entries);
    }

    public function effective(CIDGlyphClass $class, FontDescriptor $defaults): DictionaryType
    {
        $dictionary = new DictionaryType(...iterator_to_array($defaults->entries()));
        $override = $this->get($class->value);
        if ($override instanceof IndirectReference && $override->target() instanceof CIDFontMetricOverride) {
            foreach ($override->target()->entries() as $entry) {
                $dictionary = $dictionary->with($entry);
            }
        }
        return $dictionary;
    }

    public function assertCollection(CMapSystemInfo $collection): void
    {
        if ($this->systemInfo->registry->value !== $collection->registry->value || $this->systemInfo->ordering->value !== $collection->ordering->value) {
            throw new \InvalidArgumentException('FD glyph classes and their CIDFont share the character collection.');
        }
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct a new glyph-class descriptor graph.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Construct a new glyph-class descriptor graph.');
    }
}
