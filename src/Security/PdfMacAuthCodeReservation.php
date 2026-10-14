<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\ByteRangeReservation;
use PDF\Document\Signatures\SignatureContentsReservation;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Typed, writer-owned plaintext exception for the MAC byte string only. */
final class PdfMacAuthCodeReservation extends DictionaryType
{
    public function __construct(SignatureContentsReservation $contents, ByteRangeReservation $range)
    {
        parent::__construct(
            new Entry(new NameType('MACLocation'), new NameType('Standalone')),
            new Entry(new NameType('MAC'), $contents),
            new Entry(new NameType('ByteRange'), $range),
        );
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('PDF MAC reservation entries belong to the writer operation.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('PDF MAC reservation entries cannot be removed.');
    }
}
