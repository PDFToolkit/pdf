<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** Bytes reserved before digest computation (PDF32000_2008.pdf, 12.8.1, PDF page 476). */
final readonly class SignatureCapacity
{
    public function __construct(public int $bytes)
    {
        if ($bytes < 1 || $bytes > intdiv(PHP_INT_MAX - 2, 2)) {
            throw new \InvalidArgumentException('Signature capacity must be positive and representable in hexadecimal.');
        }
    }
}
