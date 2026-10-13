<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Document\Signatures\SignatureValue;

/** Explicit absence of an external evidence validator cannot satisfy required seed constraints. */
final readonly class UnavailableSignatureEvidence implements SignatureSigningEvidence
{
    public function assertTimeStamp(?SignatureTimeStampSeed $seed, SignatureValue $signature): void
    {
        throw new \LogicException('Required RFC3161 evidence needs an external validator bound to this signature.');
    }
    public function assertRevocation(SignatureCertificateFacts $certificate, SignatureValue $signature): void
    {
        throw new \LogicException('Required included revocation evidence needs an external certificate/CMS validator.');
    }
}
