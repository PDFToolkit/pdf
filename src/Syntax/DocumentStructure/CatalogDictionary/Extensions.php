<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

final class Extensions extends Entry
{
    public function __construct(DictionaryType $value)
    {
        (new \PDF\Document\Extensions\ExtensionDirectness())->validate($value);
        parent::__construct(new NameType('Extensions'), $value);
    }
}
