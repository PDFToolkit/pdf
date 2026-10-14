<?php

declare(strict_types=1);

namespace PDF\Security;

/** RFC 3394 AES-256 key wrapping without padding, TS 32004 Table 7. */
final readonly class AesKeyWrap
{
    public function wrap(string $key, FileEncryptionKey $kek): string
    {
        if (strlen($key) < 16 || strlen($key) % 8 !== 0) {
            throw new \InvalidArgumentException('RFC 3394 requires at least two complete 64-bit key blocks.');
        }
        $a = str_repeat("\xa6", 8);
        $blocks = str_split($key, 8);
        $n = count($blocks);
        for ($j = 0; $j < 6; ++$j) {
            for ($i = 0; $i < $n; ++$i) {
                $b = openssl_encrypt($a . $blocks[$i], 'aes-256-ecb', $kek->bytes(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
                if ($b === false) {
                    throw new \RuntimeException('AES key wrapping failed.');
                }
                $a = substr($b, 0, 8) ^ pack('N2', 0, $n * $j + $i + 1);
                $blocks[$i] = substr($b, 8);
            }
        }
        return $a . implode('', $blocks);
    }

    public function unwrap(string $wrapped, FileEncryptionKey $kek): string
    {
        if (strlen($wrapped) < 24 || strlen($wrapped) % 8 !== 0) {
            throw new \InvalidArgumentException('RFC 3394 wrapped data has an integrity block and at least two key blocks.');
        }
        $a = substr($wrapped, 0, 8);
        $blocks = str_split(substr($wrapped, 8), 8);
        $n = count($blocks);
        for ($j = 5; $j >= 0; --$j) {
            for ($i = $n - 1; $i >= 0; --$i) {
                $b = openssl_decrypt(($a ^ pack('N2', 0, $n * $j + $i + 1)) . $blocks[$i], 'aes-256-ecb', $kek->bytes(), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
                if ($b === false) {
                    throw new \RuntimeException('AES key unwrapping failed.');
                }
                $a = substr($b, 0, 8);
                $blocks[$i] = substr($b, 8);
            }
        }
        if (!hash_equals(str_repeat("\xa6", 8), $a)) {
            throw new \UnexpectedValueException('AES wrapped-key integrity validation failed.');
        }
        return implode('', $blocks);
    }
}
