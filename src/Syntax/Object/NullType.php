<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;

final readonly class NullType implements BasicType
{
    use SerializesPdf;
    public function toPdf(SerializationContext $context): string
    {
        return 'null';
    }
}
