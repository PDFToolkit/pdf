<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Specification\SchemaDictionary;
use PDF\Specification\TableDefinition;
use PDF\Syntax\Object\DictionaryObject\Entry;

/** Source-defined shading/stream fields; Length/Filter are subsequently supplied by StreamObject. */
final class MeshShadingDictionary extends SchemaDictionary
{
    public function __construct(TableDefinition $definition, Entry ...$entries)
    {
        parent::__construct($definition, ...$entries);
    }
}
