<?php

declare(strict_types=1);

namespace PDF\Security;

/** Optional native AES-128 backend, selected explicitly by the application. */
final readonly class OpenSslAes128Backend implements Aes128Backend
{
    public function __construct()
    {
        if (!extension_loaded('openssl') || !in_array('aes-128-cbc', openssl_get_cipher_methods(), true)) {
            throw new \RuntimeException('The native OpenSSL AES-128 CBC cipher is required.');
        }
    }

    public function encryptCbc(string $bytes, HistoricalEncryptionKey $key, InitializationVector $iv, bool $padding): string
    {
        $this->validate($bytes, $key, $padding);
        $result = openssl_encrypt($bytes, 'aes-128-cbc', $key->bytes(), OPENSSL_RAW_DATA | ($padding ? 0 : OPENSSL_ZERO_PADDING), $iv->value);
        return $result === false ? throw new \RuntimeException('AES-128 CBC encryption failed.') : $result;
    }

    public function decryptCbc(string $bytes, HistoricalEncryptionKey $key, InitializationVector $iv, bool $padding): string
    {
        $this->validate($bytes, $key, false);
        if ($padding && $bytes === '') {
            throw new \InvalidArgumentException('Padded AES ciphertext must contain a block.');
        }
        $result = openssl_decrypt($bytes, 'aes-128-cbc', $key->bytes(), OPENSSL_RAW_DATA | ($padding ? 0 : OPENSSL_ZERO_PADDING), $iv->value);
        return $result === false ? throw new \UnexpectedValueException('Invalid AES-128 CBC ciphertext or padding.') : $result;
    }

    private function validate(string $bytes, HistoricalEncryptionKey $key, bool $padding): void
    {
        if (strlen($key->bytes()) !== 16 || (!$padding && strlen($bytes) % 16 !== 0)) {
            throw new \InvalidArgumentException('AES-128 requires a 16-byte key and complete unpadded blocks.');
        }
    }
}
