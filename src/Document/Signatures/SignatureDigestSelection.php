<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\SignatureCertificateFacts;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureSeedValue;

/** Negotiate only methods the actual selected backend can emit; Table234 constrains RSA and DSA differently. */
final readonly class SignatureDigestSelection
{
    /** @param non-empty-list<SignatureDigestMethod> $supported */
    public function select(?SignatureSeedValue $seed, SignatureCertificateFacts $certificate, array $supported, ?SignatureDigestMethod $requested = null): SignatureDigestMethod
    {
        if ($supported === []) {
            throw new \LogicException('The selected handler has no supported signature digest.');
        }
        if ($requested !== null && !in_array($requested, $supported, true)) {
            throw new \LogicException('The selected backend cannot emit the requested signature digest.');
        }
        $candidates = $requested === null ? $supported : [$requested];
        foreach ($candidates as $candidate) {
            if ($seed !== null && !$seed->acceptsDigest($candidate, $certificate)) {
                continue;
            }
            return $candidate;
        }
        throw new \LogicException('The selected backend cannot emit a digest satisfying the actual mandatory seed and credential.');
    }
}
