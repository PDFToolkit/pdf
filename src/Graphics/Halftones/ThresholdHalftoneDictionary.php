<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Specification\SchemaDictionary;
use PDF\Specification\TableDefinition;
use PDF\Syntax\Object\DictionaryObject\Entry;

/** Source-defined threshold-stream fields, completed with Length/Filter by StreamObject. */
final class ThresholdHalftoneDictionary extends SchemaDictionary
{
    public function __construct(TableDefinition $definition, Entry ...$entries)
    {
        parent::__construct($definition, ...$entries);
    }
}
