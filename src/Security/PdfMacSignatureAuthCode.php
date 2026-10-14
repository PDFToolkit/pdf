<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\PendingSignature;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** ISO/TS 32004:2024 Table 6: signature location has neither MAC nor ByteRange. */
final class PdfMacSignatureAuthCode extends DictionaryType
{
    public function __construct(public readonly PendingSignature $signature)
    {
        parent::__construct(
            new Entry(new NameType('MACLocation'), new NameType('AttachedToSig')),
            new Entry(new NameType('SigObjRef'), new IndirectReference($signature)),
        );
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct a new PDF MAC signature location.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('The PDF MAC signature location is immutable.');
    }
}
