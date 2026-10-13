<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Document\Signatures\SignatureValue;

/** External validator contract: verify evidence against the actual completed signature, or throw. */
interface SignatureSigningEvidence
{
    /** Check RFC3161 token, signer/certificate chain and message imprint bound to the actual signature. */
    public function assertTimeStamp(?SignatureTimeStampSeed $seed, SignatureValue $signature): void;
    /** Check signer/issuer revocation and that verified CRL/OCSP evidence is included in this actual CMS value. */
    public function assertRevocation(SignatureCertificateFacts $certificate, SignatureValue $signature): void;
}
