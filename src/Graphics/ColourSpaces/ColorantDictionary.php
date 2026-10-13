<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Each colorant key is exactly its Separation colourant name (table 71, PDF page 170). */
final class ColorantDictionary extends DictionaryType
{
    public function __construct(Separation ...$colorants)
    {
        parent::__construct(...array_map(static fn (Separation $space): Entry => new Entry($space->colorant, $space), $colorants));
    }

    public function with(Entry $entry): static
    {
        if (!$entry->value instanceof Separation || $entry->key->value !== $entry->value->colorant->value) {
            throw new \InvalidArgumentException('A colorant entry must name the same colorant as its Separation value.');
        }
        return parent::with($entry);
    }
}
