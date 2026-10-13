<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\StringType;

/** A fixed hexadecimal slot; all bytes initially zero. */
final class SignatureContentsReservation extends StringType
{
    public function __construct(public readonly SignatureCapacity $capacity)
    {
        parent::__construct(str_repeat("\0", $capacity->bytes));
    }

    public function toPdf(SerializationContext $context): string
    {
        return '<' . str_repeat('0', 2 * $this->capacity->bytes) . '>';
    }
}
