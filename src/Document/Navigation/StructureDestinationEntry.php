<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** SD is composed independently from the required D page/named fallback. */
final class StructureDestinationEntry extends Entry
{
    public function __construct(StructureDestination|RemoteStructureDestination $destination)
    {
        parent::__construct(new NameType('SD'), $destination);
    }
}
