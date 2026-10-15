<?php

declare(strict_types=1);

namespace PDF\Specification;

use PDF\Syntax\Object\NameType;

readonly class TableDefinition
{
    /** @var array<string, EntryDefinition> */
    private array $entries;
    /** @var array<string, EntryDefinition> */
    private array $variableEntries;
    /** @var list<SemanticRule> */
    private array $semanticRules;

    /**
     * @param list<EntryDefinition> $entries
     * @param list<TableDefinition> $commonTables
     * @param list<SemanticRule> $semanticRules
     */
    public function __construct(public SourceReference $source, public string $title, array $entries, array $commonTables = [], array $semanticRules = [])
    {
        $fixed = [];
        $variable = [];
        $rules = [];
        foreach ($commonTables as $table) {
            array_push($rules, ...iterator_to_array($table->semanticRules()));
            foreach ($table->entries() as $entry) {
                $fixed[$entry->name] = $entry;
            }
            foreach ($table->variableEntries() as $entry) {
                $variable[$entry->name] = $entry;
            }
        }
        foreach ($entries as $entry) {
            if ($entry->variable) {
                $variable[$entry->name] = $entry;
            } else {
                $fixed[$entry->name] = $entry;
            }
        }
        $this->entries = $fixed;
        $this->variableEntries = $variable;
        $this->semanticRules = [...$rules, ...$semanticRules];
    }

    public function entry(NameType|string $name): EntryDefinition
    {
        $name = $name instanceof NameType ? $name->value : $name;
        return $this->entries[$name] ?? throw new \OutOfBoundsException('Unknown entry /' . $name . ' in table ' . $this->source->table . '.');
    }

    public function variableEntry(string $description): EntryDefinition
    {
        return $this->variableEntries[$description] ?? throw new \OutOfBoundsException('Unknown variable entry in table ' . $this->source->table . '.');
    }

    public function has(NameType|string $name): bool
    {
        return isset($this->entries[$name instanceof NameType ? $name->value : $name]);
    }
    /** @return iterable<EntryDefinition> */
    public function entries(): iterable
    {
        yield from $this->entries;
    }
    /** @return iterable<EntryDefinition> */
    public function variableEntries(): iterable
    {
        yield from $this->variableEntries;
    }

    /** @return iterable<SemanticRule> */
    public function semanticRules(): iterable
    {
        yield from $this->semanticRules;
    }
}
