<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Specification\SchemaDictionary;
use PDF\Specification\TableDefinition;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Shared protection for the entries determined by an assembled font graph. */
abstract class ManagedFontDictionary extends SchemaDictionary
{
    /** @param list<string> $managed */
    protected function __construct(TableDefinition $definition, private readonly array $managed, Entry ...$entries)
    {
        parent::__construct($definition, ...$entries);
    }

    public function with(Entry $entry): static
    {
        $this->assertUnmanaged($entry->key()->value);
        return parent::with($entry);
    }

    public function without(NameType|string $key): static
    {
        $this->assertUnmanaged($key instanceof NameType ? $key->value : $key);
        return parent::without($key);
    }

    protected function assertUnmanaged(string $key): void
    {
        if (in_array($key, $this->managed, true)) {
            throw new \InvalidArgumentException('The font graph manages /' . $key . '.');
        }
    }
}
