<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** PDF20_AN003-ObjectMetadataLocations.pdf p.8: a named resource, never an inline BDC/DP operand. */
final class MetadataPropertyList extends DictionaryType
{
    public function __construct(MetadataStream $metadata, Entry ...$entries)
    {
        parent::__construct(new MetadataEntry($metadata), ...$entries);
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Metadata property lists are constructor-controlled.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Metadata property lists are constructor-controlled.');
    }
}
