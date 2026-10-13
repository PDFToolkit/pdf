<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NameType;

final class CollectionSortFields extends ArrayType
{
    public function __construct(NameType ...$fields)
    {
        parent::__construct(...$fields);
    }
}
