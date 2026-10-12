<?php

declare(strict_types=1);

namespace PDF\Syntax\Object\IndirectObject;

/** Identifiers are values, never a process-wide registry. */
final readonly class ObjectIdentifier implements \Stringable
{
    public function __construct(public ObjectNumber $objectNumber, public GenerationNumber $generationNumber = new GenerationNumber())
    {
    }
    public function __toString(): string
    {
        return $this->objectNumber . ' ' . $this->generationNumber;
    }
}
