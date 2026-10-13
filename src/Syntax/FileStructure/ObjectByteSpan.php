<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** Location of an indirect object in bytes produced by this writer; not parsed external data. */
final readonly class ObjectByteSpan
{
    public function __construct(public ObjectIdentifier $identifier, public ByteOffset $offset, public int $length)
    {
        if ($length < 1 || $offset->value > PHP_INT_MAX - $length) {
            throw new \InvalidArgumentException('An indirect object span must have a positive, representable length.');
        }
    }
}
