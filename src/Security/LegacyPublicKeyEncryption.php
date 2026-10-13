<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Public-key V=1/2 without crypt filters, PDF32000_2008.pdf, 7.6.4 PDF pp.72–75. */
final readonly class LegacyPublicKeyEncryption implements FileEncryption
{
    private DictionaryType $encryptionDictionary;

    public function __construct(
        public PublicKeySubFilter $subFilter,
        public PublicKeyKeyMaterial $material,
        public PublicKeyPermissions $permissions,
        public PublicKeyCipherOperation $operation
    ) {
        if ($subFilter === PublicKeySubFilter::Pkcs7S5 || $material->scope !== PublicKeyRecipientScope::Document
            || !$material->metadata->value || $material->length->bytes() > 16 || $operation->cipher !== PublicKeyCipher::Rc4
            || !hash_equals($material->key->bytes(), $operation->key->bytes())
            || ($subFilter === PublicKeySubFilter::Pkcs7S3 && $material->length->bytes() !== 5)) {
            throw new \InvalidArgumentException('Legacy public-key encryption requires document recipients and an appropriate RC4 key.');
        }
        $version = $subFilter === PublicKeySubFilter::Pkcs7S3 ? 1 : 2;
        $entries = [new Entry(new NameType('Filter'), new NameType('Adobe.PubSec')),
            new Entry(new NameType('SubFilter'), new NameType($subFilter->value)), new Entry(new NameType('V'), new IntegerType($version)),
            new Entry(new NameType('P'), $permissions->value), new Entry(new NameType('Recipients'), $material->recipientObject())];
        if ($version === 2) {
            $entries[] = new Entry(new NameType('Length'), $material->length->value);
        }
        $this->encryptionDictionary = new DictionaryType(...$entries);
    }

    public function dictionary(): DictionaryType
    {
        return $this->encryptionDictionary;
    }

    public function metadata(): EncryptMetadata
    {
        return $this->material->metadata;
    }

    public function validateFile(FileHeader $header, DictionaryType $catalog, FileIdentifier $identifier): void
    {
        (new EffectivePdfVersion($header, $catalog))->require(
            $this->subFilter === PublicKeySubFilter::Pkcs7S3 ? PdfVersion::PDF13 : PdfVersion::PDF14,
            'Public-key security handler',
        );
    }

    public function encryptString(string $bytes, ObjectIdentifier $identifier): string
    {
        return $this->operation->encrypt($bytes, $identifier);
    }

    public function encryptStream(string $bytes, ObjectIdentifier $identifier, StreamObject $stream): string
    {
        if ((new StreamCryptFilter())->explicit($stream) !== null) {
            throw new \LogicException('Legacy public-key encryption does not support stream Crypt filters.');
        }
        return $this->operation->encrypt($bytes, $identifier);
    }

    /** @return list<Filter> */
    public function streamFilters(StreamObject $stream): array
    {
        return $stream->filters;
    }
}
