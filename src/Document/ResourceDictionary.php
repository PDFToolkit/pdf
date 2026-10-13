<?php

declare(strict_types=1);

namespace PDF\Document;

use PDF\Specification\Definition\ResourceDictionaryTable33Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;

/** Resource dictionary (ISO 32000-1, 7.8.3 and table 33). */
final class ResourceDictionary extends SchemaDictionary
{
    public const string TABLE = '33';

    public function __construct(Entry ...$entries)
    {
        parent::__construct(new ResourceDictionaryTable33Definition(), ...$entries);
    }
}
