<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\GenerationNumber;

/** 7.5.4: a 20-byte entry, including its LF line ending. */
final readonly class CrossReferenceEntry implements \Stringable
{
    public function __construct(public int $objectNumber, public ByteOffset $offset, public GenerationNumber $generation, public bool $inUse)
    {
        if ($objectNumber < 0 || $offset->value > 9999999999) {
            throw new \InvalidArgumentException('Traditional xref entry exceeds its ten-digit field.');
        }
    }
    public function __toString(): string
    {
        return sprintf("%010d %05d %s \n", $this->offset->value, $this->generation->value, $this->inUse ? 'n' : 'f');
    }
}
