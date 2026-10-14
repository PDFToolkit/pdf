<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;

/** In-process RSA/ECDSA with exact digest selection, including SHA-3. */
final readonly class OpenSslModernSignaturePrimitive implements ModernSignaturePrimitive
{
    public function sign(string $bytes, OpenSslIdentity $identity, SignatureDigestMethod $digest): string
    {
        if ($digest === SignatureDigestMethod::SHAKE256 || !openssl_sign($bytes, $signature, $identity->key(), $digest->hashName())) {
            throw new \RuntimeException('The selected in-process OpenSSL signature primitive cannot emit this algorithm.');
        }
        return $signature;
    }

    public function verify(string $bytes, string $signature, X509Certificate $certificate, SignatureDigestMethod $digest): bool
    {
        return $digest !== SignatureDigestMethod::SHAKE256 && openssl_verify($bytes, $signature, $certificate->pem(), $digest->hashName()) === 1;
    }
}
