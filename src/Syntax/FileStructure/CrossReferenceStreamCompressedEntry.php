<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;

/** Type 2: containing object-stream number and zero-based member index. */
final readonly class CrossReferenceStreamCompressedEntry extends CrossReferenceStreamEntry
{
    public function __construct(ObjectNumber $objectNumber, public ObjectNumber $objectStreamNumber, public IntegerType $index)
    {
        if ($index->value < 0 || $objectNumber->value === $objectStreamNumber->value) {
            throw new \InvalidArgumentException('A compressed object needs a non-negative index and a distinct object-stream number.');
        }
        parent::__construct($objectNumber);
    }

    public function fields(): array
    {
        return [2, $this->objectStreamNumber->value, (int) $this->index->value];
    }
}
