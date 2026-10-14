<?php

declare(strict_types=1);

namespace PDF\Security;

/** NIST SP 800-38D 5.2.1 limits plaintext to 2^39-256 bits, or 2^36-32 bytes. */
final readonly class AesGcmPlaintextLength
{
    public function __construct(public int $bytes)
    {
        // TS 32003 5.2 repeats the numeric limit using "bytes". Applying the
        // stricter referenced NIST bit limit satisfies both upper bounds.
        if ($bytes < 0 || $bytes > 68719476704) {
            throw new \InvalidArgumentException('AES-GCM plaintext is limited to 2^36-32 bytes by NIST SP 800-38D.');
        }
    }
}
