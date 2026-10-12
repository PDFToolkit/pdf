<?php

declare(strict_types=1);

namespace PDF\Document\Resources;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Named ExtGState resources (ISO 32000-1, table 33). */
final class ExtGState extends Entry
{
    public function __construct(DictionaryType|IndirectReference $dictionary)
    {
        if ($dictionary instanceof IndirectReference && !$dictionary->target() instanceof DictionaryType) {
            throw new \InvalidArgumentException('ExtGState resources require a dictionary.');
        }
        parent::__construct(new NameType('ExtGState'), $dictionary);
    }
}
