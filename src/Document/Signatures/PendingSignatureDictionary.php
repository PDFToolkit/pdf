<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\VersionedComponent;
use PDF\Specification\SchemaDictionary;
use PDF\Specification\TableDefinition;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Shared byte reservation mechanism; approval signatures and timestamps remain distinct objects. */
abstract class PendingSignatureDictionary extends SchemaDictionary implements VersionedComponent
{
    public readonly SignatureContentsReservation $contents;
    public readonly ByteRangeReservation $byteRange;

    protected function __construct(public readonly SignatureCapacity $capacity, public readonly SignatureFormat $format, public readonly NameType $handler, private readonly string $signatureType, TableDefinition $definition, Entry ...$entries)
    {
        $this->contents = new SignatureContentsReservation($capacity);
        $this->byteRange = new ByteRangeReservation();
        foreach ($entries as $entry) {
            // ISO 32000-2 EC3 issue403, physical PDF page583: these associations
            // cannot occur in a byte-range signature, even when supplied directly.
            if (in_array($entry->key->value, ['Metadata', 'AF'], true)) {
                throw new \InvalidArgumentException('Byte-range signatures cannot contain Metadata or AF associations.');
            }
            if (in_array($entry->key->value, ['Contents', 'ByteRange', 'Type', 'Filter', 'SubFilter'], true)) {
                throw new \InvalidArgumentException('Signature reservation entries are constructor-owned.');
            }
            if ($entry->value instanceof IndirectReference || $entry->value instanceof \PDF\Syntax\Object\StreamObject) {
                throw new \InvalidArgumentException('All byte-range signature dictionary values must be direct objects.');
            }
        }
        parent::__construct(
            $definition,
            new Entry(new NameType('Contents'), $this->contents),
            new Entry(new NameType('ByteRange'), $this->byteRange),
            new Entry(new NameType('Type'), new NameType($signatureType)),
            new Entry(new NameType('Filter'), $handler),
            new Entry(new NameType('SubFilter'), new NameType($format->value)),
            ...$entries,
        );
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
            throw new \InvalidArgumentException('CMS certificate chains belong in Contents; Cert must be omitted.');
        }
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require($this->signatureType === 'DocTimeStamp' ? PdfVersion::PDF20 : PdfVersion::PDF13, 'Byte-range signature dictionary');
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
