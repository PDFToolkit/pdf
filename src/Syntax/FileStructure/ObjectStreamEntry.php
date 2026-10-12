<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\StreamObject;

/** A generation-zero indirect object packed into an ObjStm (7.5.7). */
final readonly class ObjectStreamEntry
{
    public function __construct(public ObjectNumber $objectNumber, public BasicType $value)
    {
        if ($value instanceof StreamObject || $value instanceof IndirectReference) {
            throw new \InvalidArgumentException('An object-stream member cannot be a stream or consist solely of an indirect reference.');
        }
    }

    public function identifier(): ObjectIdentifier
    {
        return new ObjectIdentifier($this->objectNumber);
    }
}
