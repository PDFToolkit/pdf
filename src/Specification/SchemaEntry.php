<?php

declare(strict_types=1);

namespace PDF\Specification;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

abstract class SchemaEntry extends Entry
{
    public const string TABLE = '';
    public const string KEY = '';
    public const bool VARIABLE = false;

    public function __construct(private readonly EntryDefinition $entryDefinition, BasicType $value, ?NameType $key = null)
    {
        $entryDefinition->type->validate($value);
        if (!$entryDefinition->variable && $key !== null && $key->value !== $entryDefinition->name) {
            throw new \InvalidArgumentException('A fixed specification entry cannot change its PDF key.');
        }
        if ($entryDefinition->variable && $key === null) {
            throw new \InvalidArgumentException('A variable entry requires an explicit PDF name.');
        }
        if ($entryDefinition->variable && $entryDefinition->source->table === '368' && !in_array($key?->value, ['1.3', '2.0'], true)) {
            throw new \InvalidArgumentException('An OPI version dictionary key shall be /1.3 or /2.0.');
        }
        parent::__construct($key ?? new NameType($entryDefinition->name), $value);
    }

    public function definition(): EntryDefinition
    {
        return $this->entryDefinition;
    }
}
