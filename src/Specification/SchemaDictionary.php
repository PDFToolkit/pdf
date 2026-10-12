<?php

declare(strict_types=1);

namespace PDF\Specification;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;

abstract class SchemaDictionary extends DictionaryType
{
    public const string TABLE = '';
    /** @var list<string> */
    public const array COMMON_TABLES = [];

    public function __construct(private readonly TableDefinition $tableDefinition, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            $this->validateEntry($entry);
        }
        parent::__construct(...$entries);
    }

    public function definition(): TableDefinition
    {
        return $this->tableDefinition;
    }

    #[\NoDiscard]
    public function with(Entry $entry): static
    {
        $this->validateEntry($entry);
        return parent::with($entry);
    }

    /** Checks unconditional requirements; conditional and inherited rules remain explicit. */
    public function validate(): void
    {
        foreach ($this->entries() as $entry) {
            $this->validateEntry($entry);
        }
        foreach ($this->definition()->entries() as $definition) {
            if ($definition->requirement->unconditional() && !$this->has($definition->name)) {
                throw new \InvalidArgumentException('Table ' . $this->definition()->source->table . ': required dictionary entry /' . $definition->name . ' is missing.');
            }
        }
    }

    public function toPdf(SerializationContext $context): string
    {
        $this->validate();
        return parent::toPdf($context);
    }

    private function validateEntry(Entry $entry): void
    {
        if ($this->definition()->has($entry->key)) {
            $this->definition()->entry($entry->key)->type->validate($entry->value);
        }
        // Generic Entry is the explicit extension/variable-key escape hatch.
    }
}
