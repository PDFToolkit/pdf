<?php

declare(strict_types=1);

namespace PDF\Security;

/**
 * An explicitly selected historical AESV3/R=5 file operation (supplement page 16).
 * Ciphertext has a fresh 16-byte IV followed by AES-CBC with PKCS#7 padding.
 * CBC supplies confidentiality, not cryptographic authentication of PDF contents.
 */
final readonly class HistoricalRevision5Encryption
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
}
