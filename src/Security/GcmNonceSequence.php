<?php

declare(strict_types=1);

namespace PDF\Security;

/** One file/key-scoped invocation sequence, NIST SP 800-38D 8.2.1; no nonce reuse. */
final class GcmNonceSequence
{
    private int $counter = 0;
    private readonly string $prefix;

    public function __construct(RandomSource $random)
    {
        $this->prefix = $random->bytes(4);
        if (strlen($this->prefix) !== 4) {
            throw new \UnexpectedValueException('The nonce fixed field requires four random bytes.');
        }
    }

    public function next(): string
    {
        if ($this->counter === PHP_INT_MAX) {
            throw new \OverflowException('The file-scoped GCM invocation counter is exhausted.');
        }
        return $this->prefix . pack('N2', intdiv($this->counter, 4294967296), $this->counter++ % 4294967296);
    }
}
