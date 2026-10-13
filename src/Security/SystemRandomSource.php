<?php

declare(strict_types=1);

namespace PDF\Security;

/** Uses PHP's operating-system CSPRNG, without shared mutable state. */
final readonly class SystemRandomSource implements RandomSource
{
    public function bytes(int $length): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('A random byte count must be positive.');
        }
        return random_bytes($length);
    }
}
