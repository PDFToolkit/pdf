<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Specification\Definition\SignatureDictionaryTable252Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringType;

/** Approval/certification signature reservation, PDF32000_2008.pdf, 12.8.1, PDF pages 474–477. */
final class PendingSignature extends SchemaDictionary
{
    public readonly SignatureContentsReservation $contents;
    public readonly ByteRangeReservation $byteRange;

    public function __construct(public readonly SignatureCapacity $capacity, public readonly SignatureFormat $format,
        public readonly NameType $handler = new NameType('Adobe.PPKLite'), Entry ...$entries)
    {
        $this->contents = new SignatureContentsReservation($capacity);
        $this->byteRange = new ByteRangeReservation();
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, ['Contents', 'ByteRange', 'Type', 'Filter', 'SubFilter'], true)) {
                throw new \InvalidArgumentException('Signature reservation entries are constructor-owned.');
            }
            if ($entry->value instanceof IndirectReference) {
                throw new \InvalidArgumentException('All byte-range signature dictionary values must be direct objects.');
            }
        }
        parent::__construct(new SignatureDictionaryTable252Definition(),
            new Entry(new NameType('Contents'), $this->contents),
            new Entry(new NameType('ByteRange'), $this->byteRange),
            new Entry(new NameType('Type'), new NameType('Sig')),
            new Entry(new NameType('Filter'), $handler),
            new Entry(new NameType('SubFilter'), new NameType($format->value)), ...$entries);
        $cert = $this->get('Cert');
        if ($format === SignatureFormat::X509RsaSha1) {
            if (!$cert instanceof StringType && !$cert instanceof ArrayType) {
                throw new \InvalidArgumentException('PKCS#1 signatures require an explicit certificate chain.');
            }
            foreach ($cert instanceof ArrayType ? $cert->items : [$cert] as $certificate) {
                if (!$certificate instanceof StringType || $certificate->value === '') {
                    throw new \InvalidArgumentException('Cert contains direct nonempty DER certificate byte strings.');
                }
            }
            if ($cert instanceof ArrayType && $cert->items === []) {
                throw new \InvalidArgumentException('Cert cannot contain an empty chain.');
            }
        } elseif ($cert !== null) {
            throw new \InvalidArgumentException('PKCS#7 certificate chains belong in Contents; Cert must be omitted.');
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Construct another signature reservation with explicit entries.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Signature reservations cannot lose their signing contract.');
    }
}
