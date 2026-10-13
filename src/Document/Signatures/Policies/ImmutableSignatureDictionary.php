<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Specification\SchemaDictionary;
use PDF\Specification\TableDefinition;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Complete policy dictionaries are reconstructed explicitly, never mutated by arbitrary entries. */
abstract class ImmutableSignatureDictionary extends SchemaDictionary
{
    protected function __construct(TableDefinition $definition, Entry ...$entries)
    {
        parent::__construct($definition, ...$entries);
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct a new signature policy from its explicit values.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Construct a new signature policy from its explicit values.');
    }
}
