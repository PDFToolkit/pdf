<?php

declare(strict_types=1);

namespace PDF\Document\Resources;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Deprecated procedure-set resource entry retained for exact PDF terminology. */
final class ProcSet extends Entry
{
    public function __construct(NameType ...$sets)
    {
        foreach ($sets as $set) {
            if (!in_array($set->value, ['PDF', 'Text', 'ImageB', 'ImageC', 'ImageI'], true)) {
                throw new \InvalidArgumentException('Invalid procedure set name.');
            }
        }
        parent::__construct(new NameType('ProcSet'), new ArrayType(...$sets));
    }
}
