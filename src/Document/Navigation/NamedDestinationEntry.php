<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

final readonly class NamedDestinationEntry
{
    public function __construct(public NameType|StringType $name, public LocalDestination|DestinationDictionary $destination)
    {
    }
}
