<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\StringType;

final readonly class NameTreeEntry
{
    public function __construct(public StringType $key, public BasicType $value)
    {
    }
}
