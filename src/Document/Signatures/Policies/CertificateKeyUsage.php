<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\Object\IntegerType;

/** External X509 parser normalizes the nine key-usage bits to bit positions0–8 in Table235 order. */
final readonly class CertificateKeyUsage
{
    public function __construct(public IntegerType $bits)
    {
        if ($bits->integer() < 0 || $bits->integer() > 511) {
            throw new \InvalidArgumentException('Normalized X509 key usage has nine named bits.');
        }
    }
    public function set(int $index): bool
    {
        if ($index < 0 || $index > 8) {
            throw new \OutOfBoundsException('Key-usage index outside0–8.');
        }
        return ($this->bits->integer() & (1 << $index)) !== 0;
    }
}
