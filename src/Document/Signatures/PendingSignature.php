<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Specification\Definition\SignatureDictionaryTable252Definition;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** Approval/certification signature reservation, PDF32000_2008.pdf, 12.8.1, PDF pages 474–477. */
final class PendingSignature extends PendingSignatureDictionary
{
    public function __construct(
        SignatureCapacity $capacity,
        SignatureFormat $format,
        NameType $handler = new NameType('Adobe.PPKLite'),
        Entry ...$entries
    ) {
        if ($format === SignatureFormat::Rfc3161) {
            throw new \InvalidArgumentException('A document timestamp uses PendingDocumentTimestamp.');
        }
        parent::__construct($capacity, $format, $handler, 'Sig', new SignatureDictionaryTable252Definition(), ...$entries);
    }
}
