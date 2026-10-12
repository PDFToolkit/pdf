<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\PageDictionary;

use PDF\Document\ResourceDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Inheritable page resources entry. */
final class Resources extends Entry
{
    public function __construct(ResourceDictionary|IndirectReference $dictionary)
    {
        if ($dictionary instanceof IndirectReference && !$dictionary->target() instanceof ResourceDictionary) {
            throw new \InvalidArgumentException('Resources must reference a resource dictionary.');
        }
        parent::__construct(new NameType('Resources'), $dictionary);
    }
}
