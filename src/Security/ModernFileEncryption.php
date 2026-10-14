<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Extensions\IsoTechnicalSpecification;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\StreamObject;

/** Explicit file/key-scoped R=6 AES-CBC or R=7 authenticated AES-GCM operation. */
final readonly class ModernFileEncryption implements FileEncryption
{
    private GcmNonceSequence $nonces;

    public function __construct(public FileEncryptionKey $key, public ModernEncryptionDictionary $credentials, private Aes256Backend $backend, private RandomSource $random, ?GcmNonceSequence $nonces = null)
    {
        (new ModernStandardSecurityHandler($backend, $random))->validatePermissions($key, $credentials);
        $this->nonces = $nonces ?? new GcmNonceSequence($random);
    }

    public function encrypt(string $bytes): string
    {
        if ($this->credentials->revision === ModernSecurityRevision::Aes256Gcm) {
            return (new AesGcmCipher())->encrypt($bytes, $this->key, $this->nonces->next());
        }
        $iv = new InitializationVector($this->random->bytes(16));
        return $iv->value . $this->backend->encryptCbc($bytes, $this->key, $iv, true);
    }

    public function decrypt(string $bytes): string
    {
        if ($this->credentials->revision === ModernSecurityRevision::Aes256Gcm) {
            return (new AesGcmCipher())->decrypt($bytes, $this->key);
        }
        if (strlen($bytes) < 32 || (strlen($bytes) - 16) % 16 !== 0) {
            throw new \UnexpectedValueException('AESV3 ciphertext requires an IV and complete padded blocks.');
        }
        return $this->backend->decryptCbc(substr($bytes, 16), $this->key, new InitializationVector(substr($bytes, 0, 16)), true);
    }

    public function dictionary(): DictionaryType
    {
        return $this->credentials->dictionary();
    }

    public function metadata(): EncryptMetadata
    {
        return $this->credentials->encryptMetadata;
    }

    public function validateFile(FileHeader $header, DictionaryType $catalog, FileIdentifier $identifier): void
    {
        (new EffectivePdfVersion($header, $catalog))->require(PdfVersion::PDF20, 'Modern standard security');
        (new EncryptionVersionPolicy())->validate(new EffectivePdfVersion($header, $catalog), $this->dictionary());
        if ($this->credentials->revision === ModernSecurityRevision::Aes256Gcm) {
            IsoTechnicalSpecification::AesGcm->validate($catalog);
        }
        if ($this->credentials->kdfSalt !== null) {
            IsoTechnicalSpecification::IntegrityProtection->validate($catalog);
        }
    }

    public function encryptString(string $bytes, ObjectIdentifier $identifier): string
    {
        return $this->encrypt($bytes);
    }

    public function encryptStream(string $bytes, ObjectIdentifier $identifier, StreamObject $stream): string
    {
        $selected = (new StreamCryptFilter())->explicit($stream);
        if ($selected !== null && !in_array($selected->value, ['Identity', 'StdCF'], true)) {
            throw new \LogicException('The selected crypt filter is absent from the modern encryption dictionary.');
        }
        return $selected?->value === 'Identity' ? $bytes : $this->encrypt($bytes);
    }

    /** @return list<Filter> */
    public function streamFilters(StreamObject $stream): array
    {
        return $stream->filters;
    }
}
