<?php

declare(strict_types=1);

namespace PDF\Security;

/** NIST SP 800-38D 8.2.2: fresh 96-bit RBG IVs, bounded invocation count and duplicate rejection. */
final class GcmNonceSequence
{
    private int $counter = 0;
    /** @var array<string,true> */
    private array $used = [];

    public function __construct(private readonly RandomSource $random, private readonly int $maximumInvocations = 4294967296)
    {
        if ($maximumInvocations < 1 || $maximumInvocations > 4294967296) {
            throw new \InvalidArgumentException('A random-IV GCM operation is bounded by at most 2^32 invocations.');
        }
    }

    public function next(): string
    {
        if ($this->counter === $this->maximumInvocations) {
            throw new \OverflowException('The file-scoped GCM invocation counter is exhausted.');
        }
        $nonce = $this->random->bytes(12);
        if (strlen($nonce) !== 12 || isset($this->used[bin2hex($nonce)])) {
            throw new \UnexpectedValueException('The GCM random source returned an invalid or repeated 96-bit IV.');
        }
        $this->used[bin2hex($nonce)] = true;
        ++$this->counter;
        return $nonce;
    }

    private function __clone(): void
    {
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('A live GCM nonce allocator cannot be serialized and restarted.');
    }
}
