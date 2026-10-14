<?php

declare(strict_types=1);

namespace PDF\Document\Geospatial;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Native immutable dictionaries from ISO 32000-2 12.10, physical pages 616-621. */
abstract class GeospatialDictionary extends DictionaryType implements VersionedComponent
{
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'Geospatial features');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct a new explicit geospatial dictionary.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Geospatial dictionaries retain their constructor contracts.');
    }
}
