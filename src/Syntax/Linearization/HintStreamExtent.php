<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\Object\IntegerType;

/** Whole indirect-object extent of an overflow hint stream, Table F.1 /H (PDF page 688). */
final readonly class HintStreamExtent
{
    public function __construct(public ByteOffset $offset, public IntegerType $length)
    {
        if ($length->integer() < 1 || $offset->value > 4_294_967_295 - $length->integer()) {
            throw new \InvalidArgumentException('A hint-stream extent needs a positive length within the unsigned 32-bit file address space.');
        }
    }
}
