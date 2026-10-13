<?php

declare(strict_types=1);

namespace PDF\Security;

/** Optional native backend. Constructing it explicitly requires ext-openssl. */
final readonly class OpenSslAes256Backend implements Aes256Backend
{
    public function __construct()
    {
        if (!extension_loaded('openssl') || !in_array('aes-256-cbc', openssl_get_cipher_methods(), true)
            || !in_array('aes-256-ecb', openssl_get_cipher_methods(), true)) {
            throw new \RuntimeException('The native OpenSSL AES-256 CBC and ECB ciphers are required.');
        }
    }

    public function encryptCbc(string $bytes, FileEncryptionKey $key, InitializationVector $iv, bool $padding): string
    {
        $this->validateBlocks($bytes, $padding);
        $result = openssl_encrypt($bytes, 'aes-256-cbc', $key->bytes(), OPENSSL_RAW_DATA | ($padding ? 0 : OPENSSL_ZERO_PADDING), $iv->value);
        return $result === false ? throw new \RuntimeException('AES-256 CBC encryption failed.') : $result;
    }

    public function decryptCbc(string $bytes, FileEncryptionKey $key, InitializationVector $iv, bool $padding): string
    {
        $this->validateBlocks($bytes, false);
        if ($padding && $bytes === '') {
            throw new \InvalidArgumentException('Padded AES ciphertext must contain a block.');
        }
        $result = openssl_decrypt($bytes, 'aes-256-cbc', $key->bytes(), OPENSSL_RAW_DATA | ($padding ? 0 : OPENSSL_ZERO_PADDING), $iv->value);
        return $result === false ? throw new \UnexpectedValueException('Invalid AES-256 CBC ciphertext or padding.') : $result;
    }

    public function encryptEcb(string $bytes, FileEncryptionKey $key): string
    {
        $this->validateBlocks($bytes, false);
        $result = openssl_encrypt($bytes, 'aes-256-ecb', $key->bytes(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        return $result === false ? throw new \RuntimeException('AES-256 ECB encryption failed.') : $result;
    }

    public function decryptEcb(string $bytes, FileEncryptionKey $key): string
    {
        $this->validateBlocks($bytes, false);
        $result = openssl_decrypt($bytes, 'aes-256-ecb', $key->bytes(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        return $result === false ? throw new \UnexpectedValueException('Invalid AES-256 ECB ciphertext.') : $result;
    }

    private function validateBlocks(string $bytes, bool $padding): void
    {
        if (!$padding && strlen($bytes) % 16 !== 0) {
            throw new \InvalidArgumentException('Unpadded AES data must contain complete 16-byte blocks.');
        }
    }
}
