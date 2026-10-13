<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;

/** Table F.6, physical PDF pages 698–699. A signature is an optional MD5 resource hint. */
final readonly class SharedObjectGroup
{
    public function __construct(
        public ObjectNumber $firstObject,
        public ByteOffset $offset,
        public IntegerType $objectCount,
        public IntegerType $length,
        public ?StringType $signature = null,
    ) {
        if ($objectCount->integer() < 1 || $length->integer() < 1 || ($signature !== null && strlen($signature->value) !== 16)) {
            throw new \InvalidArgumentException('A shared object group is nonempty and its optional resource signature has 16 bytes.');
        }
    }
}
