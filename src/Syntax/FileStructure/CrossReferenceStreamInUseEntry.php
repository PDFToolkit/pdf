<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;

/** Type 1: byte offset of an ordinary indirect object and its generation. */
final readonly class CrossReferenceStreamInUseEntry extends CrossReferenceStreamEntry
{
    public function __construct(ObjectNumber $objectNumber, public ByteOffset $byteOffset, public GenerationNumber $generation = new GenerationNumber())
    {
        parent::__construct($objectNumber);
    }

    public function fields(): array
    {
        return [1, $this->byteOffset->value, $this->generation->value];
    }
}
