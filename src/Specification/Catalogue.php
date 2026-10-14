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
        $family = null;
        $edition = null;
        foreach ($definitions as $definition) {
            $candidateFamily = $definition->source->edition->family;
            if ($family !== null && $candidateFamily !== $family) {
                throw new \InvalidArgumentException('Different specification families require separate catalogues.');
            }
            if ($edition !== null && $candidateFamily === 'iso32000-2'
                && ($definition->source->edition->identifier !== $edition->identifier
                    || $definition->source->edition->corrections != $edition->corrections)) {
                throw new \InvalidArgumentException('Different specification editions or correction profiles require separate catalogues.');
            }
            $family = $candidateFamily;
            $edition = $definition->source->edition;
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
