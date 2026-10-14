<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Explicit immutable mappings owned by one namespace. */
final class NamespaceRoleMap extends DictionaryType
{
    /** @var array<string, StructureRole> */
    private readonly array $targets;

    public function __construct(NamespaceRoleMapping ...$mappings)
    {
        $targets = [];
        foreach ($mappings as $mapping) {
            $targets[$mapping->from->value] = $mapping->to;
        }
        $this->targets = $targets;
        parent::__construct(...array_map(static fn (NamespaceRoleMapping $mapping): Entry => $mapping->entry(), $mappings));
    }

    public function target(NameType $from): ?StructureRole
    {
        return $this->targets[$from->value] ?? null;
    }

    /** @return list<StructureRole> */
    public function targets(): array
    {
        return array_values($this->targets);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Build a new explicit namespace role map.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Build a new explicit namespace role map.');
    }
}
