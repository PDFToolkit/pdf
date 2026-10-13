<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;

/** One thumbnail image group, associated with its document page index. */
final readonly class ThumbnailHint
{
    public function __construct(public IntegerType $pageIndex, public ObjectNumber $firstObject, public ByteOffset $offset, public IntegerType $objectCount, public IntegerType $length)
    {
        if ($pageIndex->integer() < 0 || $objectCount->integer() < 1 || $length->integer() < 1) {
            throw new \InvalidArgumentException('A thumbnail hint requires an existing page and a nonempty image group.');
        }
    }
}
