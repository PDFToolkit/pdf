<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\IntegerType;

final readonly class NumberTreeEntry
{
    public function __construct(public IntegerType $key, public BasicType $value)
    {
    }
}
