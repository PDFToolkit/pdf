<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/**
 * An explicitly selected historical AESV3/R=5 file operation (supplement page 16).
 * Ciphertext has a fresh 16-byte IV followed by AES-CBC with PKCS#7 padding.
 * CBC supplies confidentiality, not cryptographic authentication of PDF contents.
 */
final readonly class HistoricalRevision5Encryption implements FileEncryption
{
    public function __construct(
        public FileEncryptionKey $key,
        public Revision5EncryptionDictionary $dictionary,
        private Aes256Backend $backend,
        private RandomSource $random,
    ) {
        // Caller-built credentials must agree with the supplied file key.
        (new HistoricalRevision5SecurityHandler($backend, $random))->validatePermissions($key, $dictionary);
    }

    public function encrypt(string $bytes): string
    {
        $iv = new InitializationVector($this->random->bytes(16));
        return $iv->value . $this->backend->encryptCbc($bytes, $this->key, $iv, true);
    }

    public function decrypt(string $bytes): string
    {
        if (strlen($bytes) < 32 || (strlen($bytes) - 16) % 16 !== 0) {
            throw new \UnexpectedValueException('AESV3 ciphertext must contain an IV and at least one complete encrypted block.');
        }
        return $this->backend->decryptCbc(substr($bytes, 16), $this->key, new InitializationVector(substr($bytes, 0, 16)), true);
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
        $extensions = $catalog->get('Extensions');
        $adobe = $extensions instanceof DictionaryType ? $extensions->get('ADBE') : null;
        $base = $adobe instanceof DictionaryType ? $adobe->get('BaseVersion') : null;
        $level = $adobe instanceof DictionaryType ? $adobe->get('ExtensionLevel') : null;
        $version = new EffectivePdfVersion($header, $catalog);
        (new EncryptionVersionPolicy())->validate($version, $this->dictionary());
        $version->require(PdfVersion::PDF17, 'Historical revision-5 encryption');
        if (!$base instanceof NameType || $base->value !== '1.7'
            || !$level instanceof IntegerType || $level->value < 3) {
            throw new \LogicException('Historical R=5 encryption requires PDF 1.7 and direct Adobe extension level 3 or greater.');
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
            throw new \LogicException('The named Crypt filter is absent from the R=5 encryption dictionary.');
        }
        return $selected?->value === 'Identity' ? $bytes : $this->encrypt($bytes);
    }

    /** @return list<Filter> */
    public function streamFilters(StreamObject $stream): array
    {
        return $stream->filters;
    }
}
