<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\Crypt;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\NameType;

/** Explicit ISO R=2/3/4 file encryption, including per-object keys and crypt selection. */
final readonly class HistoricalStandardEncryption implements FileEncryption
{
    public function __construct(
        public HistoricalEncryptionKey $key,
        public HistoricalStandardEncryptionDictionary $dictionary,
        public FileIdentifier $identifier,
        private Rc4Cipher $rc4 = new Rc4Cipher(),
        private RandomSource $random = new SystemRandomSource(),
        private ?Aes128Backend $aes = null,
        private HistoricalObjectKeyDerivation $objectKeys = new HistoricalObjectKeyDerivation(),
        private StreamCryptFilter $streamSelector = new StreamCryptFilter(),
    ) {
        if (strlen($key->bytes()) !== $dictionary->length->bytes() || ($dictionary->cipher === HistoricalDataCipher::Aes128 && $aes === null)) {
            throw new \InvalidArgumentException('The historical file key length and explicitly supplied cipher backend must match the dictionary.');
        }
        (new HistoricalStandardSecurityHandler())->validateKey($key, $dictionary, $identifier);
    }

    public function dictionary(): DictionaryType
    {
        return $this->dictionary->dictionary();
    }

    public function metadata(): EncryptMetadata
    {
        return $this->dictionary->encryptMetadata;
    }

    public function validateFile(FileHeader $header, DictionaryType $catalog, FileIdentifier $identifier): void
    {
        $minimum = match ($this->dictionary->revision) {
            HistoricalSecurityRevision::Revision2 => '1.1',
            HistoricalSecurityRevision::Revision3 => '1.4',
            HistoricalSecurityRevision::Revision4 => $this->dictionary->cipher === HistoricalDataCipher::Aes128
                || $this->dictionary->filters->embeddedFiles->value !== $this->dictionary->filters->streams->value ? '1.6' : '1.5',
        };
        (new EffectivePdfVersion($header, $catalog))->require(PdfVersion::from($minimum), 'Historical standard encryption');
        if (!hash_equals($this->identifier->value->value, $identifier->value->value)) {
            throw new \LogicException('The PDF version or initial file identifier disagrees with the historical security operation.');
        }
    }

    public function encryptString(string $bytes, ObjectIdentifier $identifier): string
    {
        return $this->dictionary->revision === HistoricalSecurityRevision::Revision4 && $this->dictionary->filters->strings->value === 'Identity'
            ? $bytes : $this->encrypt($bytes, $identifier);
    }

    public function encryptStream(string $bytes, ObjectIdentifier $identifier, StreamObject $stream): string
    {
        if ($this->dictionary->revision !== HistoricalSecurityRevision::Revision4 && $this->streamSelector->explicit($stream) !== null) {
            throw new \LogicException('Stream Crypt filters require a revision-4 security operation.');
        }
        if ($this->dictionary->revision === HistoricalSecurityRevision::Revision4 && $this->dictionary->filters->forStream($stream)->value === 'Identity') {
            return $bytes;
        }
        // 7.4.10 (p.46) and Algorithm 1(b) (p.66): an explicit Crypt
        // specifier uses the selected key as-is, including a writer-injected EFF selector.
        return ($this->streamFilters($stream)[0] ?? null) instanceof Crypt
            ? $this->encryptWithKey($bytes, $this->key) : $this->encrypt($bytes, $identifier);
    }

    /** @return list<Filter> EFF is a writer instruction; a differing choice is made explicit in the stream. */
    public function streamFilters(StreamObject $stream): array
    {
        $type = $stream->dictionary()->get('Type');
        if ($this->dictionary->revision === HistoricalSecurityRevision::Revision4 && $type instanceof NameType && $type->value === 'EmbeddedFile'
            && $this->dictionary->filters->embeddedFiles->value !== $this->dictionary->filters->streams->value
            && $this->streamSelector->explicit($stream) === null) {
            return [new Crypt($this->dictionary->filters->embeddedFiles), ...$stream->filters];
        }
        return $stream->filters;
    }

    public function encrypt(string $bytes, ObjectIdentifier $identifier): string
    {
        return $this->encryptWithKey($bytes, $this->objectKeys->derive($this->key, $identifier, $this->dictionary->cipher));
    }

    private function encryptWithKey(string $bytes, HistoricalEncryptionKey $key): string
    {
        if ($this->dictionary->cipher === HistoricalDataCipher::Rc4) {
            return $this->rc4->transform($bytes, $key);
        }
        $iv = new InitializationVector($this->random->bytes(16));
        return $iv->value . ($this->aes ?? throw new \LogicException('An AES backend is required.'))->encryptCbc($bytes, $key, $iv, true);
    }

    public function decrypt(string $bytes, ObjectIdentifier $identifier): string
    {
        $key = $this->objectKeys->derive($this->key, $identifier, $this->dictionary->cipher);
        if ($this->dictionary->cipher === HistoricalDataCipher::Rc4) {
            return $this->rc4->transform($bytes, $key);
        }
        if (strlen($bytes) < 32 || (strlen($bytes) - 16) % 16 !== 0) {
            throw new \UnexpectedValueException('AESV2 ciphertext requires an IV and complete padded blocks.');
        }
        return ($this->aes ?? throw new \LogicException('An AES backend is required.'))->decryptCbc(substr($bytes, 16), $key, new InitializationVector(substr($bytes, 0, 16)), true);
    }
}
