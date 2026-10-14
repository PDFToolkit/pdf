<?php

declare(strict_types=1);

namespace PDF\Security;

/** ISO 32000-2:2020 Algorithm 2.B; applies unchanged to TS 32003 R=7. */
final readonly class Revision6PasswordHash
{
    public function digest(PreparedPassword $password, string $salt, string $userEntry = ''): string
    {
        if (strlen($salt) !== 8 || !in_array(strlen($userEntry), [0, 48], true)) {
            throw new \InvalidArgumentException('Algorithm 2.B requires an eight-byte salt and optionally the 48-byte U entry.');
        }
        $key = hash('sha256', $password->bytes() . $salt . $userEntry, true);
        $round = 0;
        do {
            $input = str_repeat($password->bytes() . $key . $userEntry, 64);
            $encrypted = openssl_encrypt($input, 'aes-128-cbc', substr($key, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 16, 16));
            if ($encrypted === false) {
                throw new \RuntimeException('Algorithm 2.B AES-128 encryption failed.');
            }
            $sum = array_sum(unpack('C*', substr($encrypted, 0, 16)) ?: []);
            $key = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $encrypted, true);
            ++$round;
        } while ($round < 64 || ord($encrypted[strlen($encrypted) - 1]) > $round - 32);
        return substr($key, 0, 32);
    }
}
