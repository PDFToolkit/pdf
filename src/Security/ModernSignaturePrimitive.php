<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;

/** Explicit cryptographic provider: signs DER signedAttrs, never a PDF byte range directly. */
interface ModernSignaturePrimitive
{
    public function sign(string $bytes, OpenSslIdentity $identity, SignatureDigestMethod $digest): string;
    public function verify(string $bytes, string $signature, X509Certificate $certificate, SignatureDigestMethod $digest): bool;
}
