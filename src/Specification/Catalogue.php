<?php

declare(strict_types=1);

namespace PDF\Specification;

/** Explicit immutable aggregation; definitions have no global registry or cache. */
final readonly class Catalogue
{
    /** @var array<string, TableDefinition> */
    private array $tables;

    public function __construct(TableDefinition ...$definitions)
    {
        $tables = [];
        foreach ($definitions as $definition) {
            if (isset($tables[$definition->source->table])) {
                throw new \InvalidArgumentException('Duplicate specification table.');
            }
            $tables[$definition->source->table] = $definition;
        }
        $this->tables = $tables;
    }

    public function dictionary(string $table): TableDefinition
    {
        return $this->tables[$table] ?? throw new \OutOfBoundsException('Unknown dictionary table: ' . $table);
    }

    /** @return iterable<TableDefinition> */
    public function definitions(): iterable
    {
        yield from $this->tables;
    }
}
