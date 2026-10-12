<?php

declare(strict_types=1);

namespace PDF\Text;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** A font resource selection and size, not a font resource dictionary (9.3.1). */
final readonly class Font implements \Stringable
{
    public function __construct(public NameType $resourceName, public NumericType $size)
    {
    }

    public function toPdf(SerializationContext $context): string
    {
        return $context->serialize($this->resourceName) . ' ' . $context->serialize($this->size);
    }

    public function __toString(): string
    {
        return $this->toPdf(new SerializationContext());
    }
}
