<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;

/** The three unsigned fields of one cross-reference-stream record (7.5.8.3). */
abstract readonly class CrossReferenceStreamEntry
{
    protected function __construct(public ObjectNumber|IntegerType $objectNumber)
    {
        if ($objectNumber->value < 0) {
            throw new \InvalidArgumentException('A cross-reference object number cannot be negative.');
        }
    }

    public function number(): int
    {
        return (int) $this->objectNumber->value;
    }

    /** @return array{int, int, int} */
    abstract public function fields(): array;
}
