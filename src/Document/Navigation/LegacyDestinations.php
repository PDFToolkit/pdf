<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Name keys in catalogue Dests, permitted alongside Names.Dests; PDF1.1. */
final class LegacyDestinations extends DictionaryType implements VersionedComponent
{
    public function __construct(NamedDestinationEntry ...$destinations)
    {
        $entries = [];
        foreach ($destinations as $destination) {
            if (!$destination->name instanceof NameType) {
                throw new \InvalidArgumentException('Catalogue Dests uses name keys; string keys belong to NamedDestinations.');
            }
            $entries[] = new Entry($destination->name, $destination->destination);
        }
        parent::__construct(...$entries);
    }

    public function catalogEntry(): Entry
    {
        return new Entry(new NameType('Dests'), new IndirectReference($this));
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $dests = $catalog->get('Dests');
        if (!$dests instanceof IndirectReference || $dests->target() !== $this) {
            throw new \InvalidArgumentException('LegacyDestinations must be the actual catalogue Dests.');
        }
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF11, 'Name-object destinations');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Supply the explicit legacy destination mapping in the constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Supply the explicit legacy destination mapping in the constructor.');
    }
}
