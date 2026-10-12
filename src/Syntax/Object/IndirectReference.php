<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

final readonly class IndirectReference implements BasicType
{
    use SerializesPdf;
    public function __construct(public BasicType $object, public ?ObjectIdentifier $identifier = null)
    {
        if ($object instanceof self) {
            throw new \InvalidArgumentException('An indirect reference cannot target another reference.');
        }
    }
    public function target(): BasicType
    {
        return $this->object;
    }
    public function toPdf(SerializationContext $context): string
    {
        return $context->reference($this) . ' R';
    }
}
