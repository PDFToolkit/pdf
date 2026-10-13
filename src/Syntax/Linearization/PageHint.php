<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\Object\IntegerType;

/** Table F.4, physical PDF pages 696–697. Offsets omit the primary hint stream. */
final readonly class PageHint
{
    /** @var list<SharedObjectReference> */
    public array $sharedObjects;

    public function __construct(
        public IntegerType $objectCount,
        public ByteOffset $offset,
        public IntegerType $length,
        public IntegerType $contentOffset,
        public IntegerType $contentLength,
        SharedObjectReference ...$sharedObjects,
    ) {
        if ($objectCount->integer() < 1 || $length->integer() < 1 || $contentOffset->integer() < 0 || $contentLength->integer() < 0
            || $contentOffset->integer() > $length->integer() || $contentLength->integer() > $length->integer() - $contentOffset->integer()) {
            throw new \InvalidArgumentException('Page hints require a nonempty object range and a content range inside it.');
        }
        $seen = [];
        foreach ($sharedObjects as $reference) {
            if (isset($seen[$reference->identifier->integer()])) {
                throw new \InvalidArgumentException('A page hint must enumerate each shared group only once.');
            }
            $seen[$reference->identifier->integer()] = true;
        }
        $this->sharedObjects = array_values($sharedObjects);
    }
}
