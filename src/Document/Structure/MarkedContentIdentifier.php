<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Zero-based parent-array index (14.7.4.2–14.7.4.4, PDF pages 568–574). */
final readonly class MarkedContentIdentifier
{
    public function __construct(public IntegerType $number)
    {
        if ($number->integer() < 0) {
            throw new \InvalidArgumentException('A marked-content identifier must be non-negative.');
        }
    }

    public function begin(NameType|StandardStructureType $type): BeginMarkedContentWithProperties
    {
        return new BeginMarkedContentWithProperties(
            $type instanceof StandardStructureType ? $type->name() : $type,
            new DictionaryType(new Entry(new NameType('MCID'), $this->number)),
        );
    }
}
