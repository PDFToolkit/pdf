<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Cycles are legal; resolution terminates at a recognized mapped role or a visited name. */
final class RoleMap extends DictionaryType
{
    public function __construct(RoleMapping ...$mappings)
    {
        parent::__construct(...array_map(static fn (RoleMapping $mapping): Entry => new Entry($mapping->from, $mapping->to), $mappings));
    }

    /** PDF 1.5+ remaps even a standard initial name before interpreting it. */
    public function resolve(NameType|StandardStructureType $type): ?StandardStructureType
    {
        $name = $type->value;
        $seen = [];
        while (!isset($seen[$name])) {
            $seen[$name] = true;
            $mapping = $this->get($name);
            if (!$mapping instanceof NameType) {
                return StandardStructureType::tryFrom($name);
            }
            $name = $mapping->value;
            $standard = StandardStructureType::tryFrom($name);
            if ($standard !== null) {
                return $standard;
            }
        }
        return null;
    }

    public function with(Entry $entry): static
    {
        if (!$entry->value instanceof NameType) {
            throw new \InvalidArgumentException('A role mapping must target a name.');
        }
        return parent::with($entry);
    }
}
