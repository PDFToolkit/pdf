<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\Crypt;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Public-key V=4/5 file operation with independent named crypt keys and actual stream selectors. */
final readonly class PublicKeyEncryption implements FileEncryption
{
    public int $version;
    private DictionaryType $encryptionDictionary;

    public function __construct(public PublicKeyFilterConfiguration $filters, public PublicKeyPermissions $permissions, public ?string $kdfSalt = null)
    {
        $version = 4;
        $length = 40;
        $entries = [];
        foreach ($filters->filters() as $filter) {
            if ($filter->operation->cipher === PublicKeyCipher::Aes256) {
                $version = max($version, 5);
            } elseif ($filter->operation->cipher === PublicKeyCipher::Aes256Gcm) {
                $version = 6;
            }
            $length = max($length, $filter->material->length->value->integer());
            $entries[] = new Entry($filter->name, $filter->dictionary());
        }
        $this->version = $version;
        if ($kdfSalt !== null && (strlen($kdfSalt) !== 32 || $version < 5 || !$filters->selected(new NameType('DefaultCryptFilter'))?->material->key instanceof FileEncryptionKey)) {
            throw new \InvalidArgumentException('Public-key PDF MAC requires V>=5, a 32-byte salt and DefaultCryptFilter file key.');
        }
        $this->encryptionDictionary = new DictionaryType(
            new Entry(new NameType('Filter'), new NameType('Adobe.PubSec')),
            new Entry(new NameType('SubFilter'), new NameType('adbe.pkcs7.s5')),
            new Entry(new NameType('V'), new IntegerType($version)),
            new Entry(new NameType('Length'), new IntegerType($length)),
            // Historical compatibility P does not establish the permission words in CMS recipients.
            new Entry(new NameType('P'), $permissions->value),
            new Entry(new NameType('CF'), new DictionaryType(...$entries)),
            new Entry(new NameType('StrF'), $filters->strings),
            new Entry(new NameType('StmF'), $filters->streams),
            new Entry(new NameType('EFF'), $filters->embeddedFiles),
            ...($kdfSalt === null ? [] : [new Entry(new NameType('KDFSalt'), new \PDF\Syntax\Object\StringObject\HexadecimalString($kdfSalt))]),
        );
    }

    public function dictionary(): DictionaryType
    {
        return $this->encryptionDictionary;
    }

    public function metadata(): EncryptMetadata
    {
        // Identity does not declare EncryptMetadata=false. Its normal stream selection
        // leaves metadata clear while preserving an explicit per-stream Crypt override.
        return $this->filters->selected($this->filters->streams)?->material->metadata ?? new EncryptMetadata(true);
    }

    public function validateFile(FileHeader $header, DictionaryType $catalog, FileIdentifier $identifier): void
    {
        $aes128 = false;
        foreach ($this->filters->filters() as $filter) {
            $aes128 = $aes128 || $filter->operation->cipher === PublicKeyCipher::Aes128;
        }
        $minimum = $this->version === 5 ? '1.7' : ($aes128 || $this->filters->embeddedFiles->value !== $this->filters->streams->value ? '1.6' : '1.5');
        $effective = new EffectivePdfVersion($header, $catalog);
        if ($this->version === 6) {
            $effective->require(PdfVersion::PDF20, 'Public-key AESV4 encryption');
            \PDF\Document\Extensions\IsoTechnicalSpecification::AesGcm->validate($catalog);
        }
        if ($this->kdfSalt !== null) {
            $effective->require(PdfVersion::PDF20, 'Public-key PDF MAC');
            \PDF\Document\Extensions\IsoTechnicalSpecification::IntegrityProtection->validate($catalog);
        }
        $effective->require(PdfVersion::from($minimum), 'Public-key crypt operation');
        foreach ($this->filters->filters() as $filter) {
            (new PublicKeyVersionValidation())->validate($effective, $filter->material, $this->kdfSalt !== null);
        }
        if ($this->version === 5 && $effective->value !== PdfVersion::PDF20) {
            $extensions = $catalog->get('Extensions');
            $adobe = $extensions instanceof DictionaryType ? $extensions->get('ADBE') : null;
            $base = $adobe instanceof DictionaryType ? $adobe->get('BaseVersion') : null;
            $level = $adobe instanceof DictionaryType ? $adobe->get('ExtensionLevel') : null;
            if (!$base instanceof NameType || $base->value !== '1.7' || !$level instanceof IntegerType || $level->value < 3) {
                throw new \LogicException('AESV3 public-key encryption requires a direct Adobe 1.7 ExtensionLevel 3 declaration.');
            }
        }
    }

    public function encryptString(string $bytes, ObjectIdentifier $identifier): string
    {
        return $this->filters->selected($this->filters->strings)?->operation->encrypt($bytes, $identifier) ?? $bytes;
    }

    public function encryptStream(string $bytes, ObjectIdentifier $identifier, StreamObject $stream): string
    {
        $operation = $this->filters->forStream($stream)?->operation;
        return ($this->streamFilters($stream)[0] ?? null) instanceof Crypt
            ? ($operation?->encryptDirect($bytes) ?? $bytes) : ($operation?->encrypt($bytes, $identifier) ?? $bytes);
    }

    /** @return list<Filter> */
    public function streamFilters(StreamObject $stream): array
    {
        $type = $stream->dictionary()->get('Type');
        if ($type instanceof NameType && $type->value === 'EmbeddedFile'
            && $this->filters->embeddedFiles->value !== $this->filters->streams->value
            && (new StreamCryptFilter())->explicit($stream) === null) {
            return [new Crypt($this->filters->embeddedFiles), ...$stream->filters];
        }
        return $stream->filters;
    }
}
