<?php

declare(strict_types=1);

namespace PDF\Security;

/** Historical RC4 primitive used by ISO 7.6.2–7.6.3, PDF pages 65–72. */
final readonly class Rc4Cipher
{
    public function transform(string $bytes, HistoricalEncryptionKey $key): string
    {
        $secret = $key->bytes();
        $state = range(0, 255);
        $j = 0;
        for ($i = 0; $i < 256; ++$i) {
            $j = ($j + $state[$i] + ord($secret[$i % strlen($secret)])) & 255;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
        }
        $i = $j = 0;
        $result = $bytes;
        for ($offset = 0, $count = strlen($bytes); $offset < $count; ++$offset) {
            $i = ($i + 1) & 255;
            $j = ($j + $state[$i]) & 255;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $result[$offset] = chr(ord($bytes[$offset]) ^ $state[($state[$i] + $state[$j]) & 255]);
        }
        return $result;
    }
}
