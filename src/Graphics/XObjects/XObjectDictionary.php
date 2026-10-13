<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Specification\SchemaDictionary;
use PDF\Specification\TableDefinition;
use PDF\Syntax\Object\DictionaryObject\Entry;

/** Source-defined stream fields, completed with Length/Filter by StreamObject. */
final class XObjectDictionary extends SchemaDictionary
{
    public function __construct(TableDefinition $definition, Entry ...$entries)
    {
        parent::__construct($definition, ...$entries);
    }
}
