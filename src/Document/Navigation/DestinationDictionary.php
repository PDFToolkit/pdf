<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Named destination value with optional attributes, ISO12.3.2.3 p375. */
final class DestinationDictionary extends DictionaryType
{
    public function __construct(public readonly LocalDestination $destination, Entry ...$attributes)
    {
        foreach ($attributes as $entry) {
            if ($entry->key->value === 'D') {
                throw new \InvalidArgumentException('The destination D is supplied explicitly by the constructor.');
            }
        }
        parent::__construct(new Entry(new NameType('D'), $destination), ...$attributes);
    }

    public function with(Entry $entry): static
    {
        if ($entry->key->value === 'D') {
            throw new \LogicException('The named destination target cannot be replaced.');
        }
        return parent::with($entry);
    }

    public function without(NameType|string $key): static
    {
        if (($key instanceof NameType ? $key->value : $key) === 'D') {
            throw new \LogicException('The named destination target cannot be removed.');
        }
        return parent::without($key);
    }
}
