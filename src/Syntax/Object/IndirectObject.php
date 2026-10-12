<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Serialization\SerializationContext;

final readonly class IndirectObject implements \Stringable
{
    public function __construct(public BasicType $value, public ObjectIdentifier $identifier)
    {
    }
    public function toPdf(SerializationContext $context): string
    {
        $context->register($this->value, $this->identifier);
        return $this->identifier . " obj\n" . $context->serializeIndirect($this->value) . "\nendobj\n";
    }
    public function __toString(): string
    {
        return $this->toPdf(new SerializationContext());
    }
}
