<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Explicit ownership: attach to the resource described, never inherited to embedded content. */
final class MetadataEntry extends Entry
{
    public function __construct(MetadataStream $stream)
    {
        parent::__construct(new NameType('Metadata'), new IndirectReference($stream));
    }
}
