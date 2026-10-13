<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StreamObject;

/** Explicit decoded-byte boundary; it may divide a hint header or a packed item vector. */
final readonly class HintStreamSplit
{
    public function __construct(public IntegerType $primaryDecodedBytes)
    {
        if ($primaryDecodedBytes->integer() < 1 || $primaryDecodedBytes->integer() > 4_294_967_295) {
            throw new \InvalidArgumentException('The explicit primary hint byte count must be positive and representable.');
        }
    }

    /** @return array{StreamObject, OverflowHintStream} */
    public function split(PrimaryHintStream $stream): array
    {
        $bytes = $stream->data;
        if (!is_string($bytes) || $this->primaryDecodedBytes->integer() >= strlen($bytes)) {
            throw new \LengthException('The split must leave nonempty primary and overflow hint data.');
        }
        return [
            new StreamObject(substr($bytes, 0, $this->primaryDecodedBytes->integer()), $stream->dictionary()),
            new OverflowHintStream(substr($bytes, $this->primaryDecodedBytes->integer())),
        ];
    }
}
