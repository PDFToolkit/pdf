<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;

/** Table F.9, physical PDF page 701: one contiguous document-level object group. */
readonly class GenericHintTable
{
    public function __construct(public ObjectNumber $firstObject, public ByteOffset $offset, public IntegerType $objectCount, public IntegerType $length)
    {
        if ($objectCount->integer() < 1 || $length->integer() < 1) {
            throw new \InvalidArgumentException('A generic hint table describes a nonempty object group.');
        }
    }

    public function bytes(): string
    {
        $output = new HintBitStream();
        foreach ([$this->firstObject->value, $this->offset->value, $this->objectCount->integer(), $this->length->integer()] as $value) {
            $output->write(new HintField(new IntegerType($value), new IntegerType(32)));
        }
        return $output->bytes();
    }
}
