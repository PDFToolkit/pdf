<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IntegerType;

/** Type 0: linked free-object number and generation. */
final readonly class CrossReferenceStreamFreeEntry extends CrossReferenceStreamEntry
{
    public function __construct(IntegerType $objectNumber, public IntegerType $nextFreeObjectNumber, public GenerationNumber $generation = new GenerationNumber())
    {
        if ($nextFreeObjectNumber->value < 0) {
            throw new \InvalidArgumentException('A free-list link cannot be negative.');
        }
        if ($objectNumber->value === 0 && $generation->value !== 65535) {
            throw new \InvalidArgumentException('Free object zero must have generation 65535.');
        }
        parent::__construct($objectNumber);
    }

    public function fields(): array
    {
        return [0, (int) $this->nextFreeObjectNumber->value, $this->generation->value];
    }
}
