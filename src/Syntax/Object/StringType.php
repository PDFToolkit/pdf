<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;

/** A byte string; text encoding belongs to TextString, not to this fundamental type. */
abstract class StringType implements BasicType
{
    use SerializesPdf;
    public function __construct(public readonly string $value)
    {
    }
}
