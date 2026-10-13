<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\CertificateChain;

/** Exact unpadded handler output and the byte ranges that it must mathematically authenticate. */
final readonly class SignatureVerificationInput
{
    public function __construct(public string $coveredBytes, public SignatureValue $value, public SignatureFormat $format, public CertificateChain $certificate)
    {
    }
}
