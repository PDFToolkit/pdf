<?php

declare(strict_types=1);

namespace PDF\Security;

/** TS 32003 5.2: 256-bit key, nil AAD, 12-byte IV and 16-byte authentication tag. */
final readonly class AesGcmCipher
{
    public function encrypt(string $plain, FileEncryptionKey $key, string $nonce): string
    {
        new AesGcmPlaintextLength(strlen($plain));
        if (strlen($nonce) !== 12) {
            throw new \InvalidArgumentException('AESV4 requires a 12-byte IV.');
        }
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', $key->bytes(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($encrypted === false || strlen($tag) !== 16) {
            throw new \RuntimeException('AES-GCM authenticated encryption failed.');
        }
        return $nonce . $encrypted . $tag;
    }

    public function decrypt(string $encrypted, FileEncryptionKey $key): string
    {
        if (strlen($encrypted) < 28 || strlen($encrypted) - 28 > 68719476704) {
            throw new \UnexpectedValueException('AESV4 ciphertext requires its complete IV and authentication tag.');
        }
        $plain = openssl_decrypt(substr($encrypted, 12, -16), 'aes-256-gcm', $key->bytes(), OPENSSL_RAW_DATA, substr($encrypted, 0, 12), substr($encrypted, -16), '');
        return $plain === false ? throw new \UnexpectedValueException('AESV4 ciphertext authentication failed.') : $plain;
    }
}
