<?php

declare(strict_types=1);

namespace PDF\Document\Parts;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** A page's reciprocal link to its one owning document-part leaf. */
final class DocumentPartEntry extends Entry
{
    public function __construct(DocumentPart $part)
    {
        parent::__construct(new NameType('DPart'), new IndirectReference($part));
    }
}
