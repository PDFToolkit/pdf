<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** Per-filter key/cipher binding, including object-specific RC4/AESV2 and object-independent AESV3. */
final readonly class PublicKeyCipherOperation
{
    private GcmNonceSequence $nonces;
    public function __construct(
        public PublicKeyCipher $cipher,
        public HistoricalEncryptionKey|FileEncryptionKey $key,
        private ?Aes128Backend $aes128 = null,
        private ?Aes256Backend $aes256 = null,
        private RandomSource $random = new SystemRandomSource(),
        private Rc4Cipher $rc4 = new Rc4Cipher(),
        private HistoricalObjectKeyDerivation $objectKeys = new HistoricalObjectKeyDerivation(),
        ?GcmNonceSequence $nonces = null,
    ) {
        if (in_array($cipher, [PublicKeyCipher::Aes256, PublicKeyCipher::Aes256Gcm], true) ? (!$key instanceof FileEncryptionKey || ($cipher === PublicKeyCipher::Aes256 && $aes256 === null))
            : (!$key instanceof HistoricalEncryptionKey || ($cipher === PublicKeyCipher::Aes128 && (strlen($key->bytes()) !== 16 || $aes128 === null)))) {
            throw new \InvalidArgumentException('Public-key cipher, key length and explicitly selected backend must agree.');
        }
        $this->nonces = $nonces ?? new GcmNonceSequence($random);
    }

    public function encrypt(string $bytes, ObjectIdentifier $identifier): string
    {
        return $this->encryptWithKey($bytes, $this->objectKey($identifier));
    }

    /** An explicit stream Crypt specifier bypasses object-key derivation (7.4.10). */
    public function encryptDirect(string $bytes): string
    {
        return $this->encryptWithKey($bytes, $this->key);
    }

    private function encryptWithKey(string $bytes, HistoricalEncryptionKey|FileEncryptionKey $key): string
    {
        if ($this->cipher === PublicKeyCipher::Aes256Gcm && $key instanceof FileEncryptionKey) {
            return (new AesGcmCipher())->encrypt($bytes, $key, $this->nonces->next());
        }
        if ($this->cipher === PublicKeyCipher::Rc4 && $key instanceof HistoricalEncryptionKey) {
            return $this->rc4->transform($bytes, $key);
        }
        $iv = new InitializationVector($this->random->bytes(16));
        $encrypted = $key instanceof FileEncryptionKey
            ? ($this->aes256 ?? throw new \LogicException('AESV3 backend missing.'))->encryptCbc($bytes, $key, $iv, true)
            : ($this->aes128 ?? throw new \LogicException('AESV2 backend missing.'))->encryptCbc($bytes, $key, $iv, true);
        return $iv->value . $encrypted;
    }

    public function decrypt(string $bytes, ObjectIdentifier $identifier): string
    {
        return $this->decryptWithKey($bytes, $this->objectKey($identifier));
    }

    public function decryptDirect(string $bytes): string
    {
        return $this->decryptWithKey($bytes, $this->key);
    }

    private function decryptWithKey(string $bytes, HistoricalEncryptionKey|FileEncryptionKey $key): string
    {
        if ($this->cipher === PublicKeyCipher::Aes256Gcm && $key instanceof FileEncryptionKey) {
            return (new AesGcmCipher())->decrypt($bytes, $key);
        }
        if ($this->cipher === PublicKeyCipher::Rc4 && $key instanceof HistoricalEncryptionKey) {
            return $this->rc4->transform($bytes, $key);
        }
        if (strlen($bytes) < 32 || strlen($bytes) % 16 !== 0) {
            throw new \UnexpectedValueException('AES ciphertext must contain an IV and complete padded blocks.');
        }
        $iv = new InitializationVector(substr($bytes, 0, 16));
        return $key instanceof FileEncryptionKey
            ? ($this->aes256 ?? throw new \LogicException('AESV3 backend missing.'))->decryptCbc(substr($bytes, 16), $key, $iv, true)
            : ($this->aes128 ?? throw new \LogicException('AESV2 backend missing.'))->decryptCbc(substr($bytes, 16), $key, $iv, true);
    }

    private function objectKey(ObjectIdentifier $identifier): HistoricalEncryptionKey|FileEncryptionKey
    {
        return $this->key instanceof FileEncryptionKey ? $this->key : $this->objectKeys->derive(
            $this->key,
            $identifier,
            $this->cipher === PublicKeyCipher::Aes128 ? HistoricalDataCipher::Aes128 : HistoricalDataCipher::Rc4
        );
    }
}
