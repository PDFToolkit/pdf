<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** Explicit binary handler output; validation belongs to the selected signature backend. */
final readonly class SignatureValue
{
    public function __construct(public string $bytes)
    {
        if ($bytes === '') {
            throw new \InvalidArgumentException('A signature value cannot be empty.');
        }
    }
}
