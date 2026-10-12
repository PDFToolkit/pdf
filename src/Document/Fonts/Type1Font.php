<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** A standard Type 1 font dictionary; additional entries remain explicit objects. */
final class Type1Font extends DictionaryType
{
    public function __construct(StandardFont $baseFont, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if (in_array($entry->key()->value, ['Type', 'Subtype', 'BaseFont'], true)) {
                throw new \InvalidArgumentException('Type, Subtype and BaseFont are supplied by the font constructor.');
            }
        }
        parent::__construct(
            new Entry(new NameType('Type'), new NameType('Font')),
            new Entry(new NameType('Subtype'), new NameType('Type1')),
            new Entry(new NameType('BaseFont'), new NameType($baseFont->value)),
            ...$entries,
        );
    }

    public function with(Entry $entry): static
    {
        if (in_array($entry->key()->value, ['Type', 'Subtype', 'BaseFont'], true)) {
            throw new \InvalidArgumentException('Required font entries cannot be replaced.');
        }

        return parent::with($entry);
    }

    public function without(NameType|string $key): static
    {
        if (in_array($key instanceof NameType ? $key->value : $key, ['Type', 'Subtype', 'BaseFont'], true)) {
            throw new \InvalidArgumentException('Required font entries cannot be removed.');
        }

        return parent::without($key);
    }
}
