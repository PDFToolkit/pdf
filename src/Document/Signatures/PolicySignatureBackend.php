<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\SignatureCertificateFacts;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureHandlerCapabilities;

/** Explicit handler facts and actual-output verification for field policies. */
interface PolicySignatureBackend extends CertificateAwareSignatureBackend, VersionedSignatureBackend
{
    public function capabilities(): SignatureHandlerCapabilities;
    /** @return non-empty-list<SignatureDigestMethod> Digests this backend actually emits, in preference order. */
    public function supportedDigests(SignatureFormat $format): array;
    public function certificateFacts(): SignatureCertificateFacts;
    public function outputVerifier(): SignatureOutputVerifier;
    public function supportsEvidence(SignatureEvidenceCapability $capability): bool;
    public function signWithDigest(string $coveredBytes, SignatureFormat $format, SignatureDigestMethod $digest): SignatureValue;
}
