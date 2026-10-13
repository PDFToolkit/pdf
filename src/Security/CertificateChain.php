<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\ArrayType;

/** Signing certificate first, followed by explicit intermediate certificates (12.8.3). */
final readonly class CertificateChain
{
    /** @var list<X509Certificate> */
    public array $intermediates;

    public function __construct(public X509Certificate $signer, X509Certificate ...$intermediates)
    {
        $this->intermediates = array_values($intermediates);
    }

    public function object(): ArrayType
    {
        return new ArrayType($this->signer->value, ...array_map(static fn (X509Certificate $certificate) => $certificate->value, $this->intermediates));
    }
}
