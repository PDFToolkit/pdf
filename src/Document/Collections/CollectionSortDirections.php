<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;

final class CollectionSortDirections extends ArrayType
{
    public function __construct(BooleanType ...$directions)
    {
        parent::__construct(...$directions);
    }
}
