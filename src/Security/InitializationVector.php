<?php

declare(strict_types=1);

namespace PDF\Security;

/** The AES block size is 16 bytes (supplement PDF page 16, Algorithm 3.1a). */
final readonly class InitializationVector
{
    public function __construct(public string $value)
    {
        if (strlen($value) !== 16) {
            throw new \InvalidArgumentException('An AES initialization vector must contain exactly 16 bytes.');
        }
    }
}
