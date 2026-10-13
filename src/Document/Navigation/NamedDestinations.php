<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\CatalogComponent;
use PDF\Document\VersionedComponent;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DocumentStructure\NameDictionary;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** String keys in Names.Dests, including dictionary values; PDF1.2/12.3.2.3. */
final readonly class NamedDestinations implements CatalogComponent, VersionedComponent
{
    public readonly NameTree $tree;

    public function __construct(NamedDestinationEntry ...$entries)
    {
        $pairs = [];
        foreach ($entries as $entry) {
            if (!$entry->name instanceof StringType) {
                throw new \InvalidArgumentException('Names.Dests uses byte string keys; name keys belong to LegacyDestinations.');
            }
            $pairs[] = new NameTreeEntry($entry->name, $entry->destination);
        }
        $this->tree = new NameTree(...$pairs);
    }

    public function namesEntry(): Entry
    {
        return new Entry(new NameType('Dests'), $this->tree);
    }

    /** Convenience for a catalogue containing only this name-tree category. */
    public function catalogEntry(): Entry
    {
        return new Entry(new NameType('Names'), new NameDictionary($this->namesEntry()));
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $names = $catalog->get('Names');
        $names = $names instanceof IndirectReference ? $names->target() : $names;
        $tree = $names instanceof DictionaryType ? $names->get('Dests') : null;
        $tree = $tree instanceof IndirectReference ? $tree->target() : $tree;
        if ($tree !== $this->tree) {
            throw new \InvalidArgumentException('NamedDestinations must belong to the actual catalogue Names.Dests.');
        }
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF12, 'String-named destinations');
    }

}
