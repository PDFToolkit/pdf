<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;

final readonly class BooleanType implements BasicType
{
    use SerializesPdf;
    public function __construct(public bool $value)
    {
    }
    public function toPdf(SerializationContext $context): string
    {
        return $this->value ? 'true' : 'false';
    }
}
