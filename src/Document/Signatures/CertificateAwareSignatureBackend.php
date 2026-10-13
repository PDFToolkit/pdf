<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\CertificateChain;

/** A backend exposes the certificate actually associated with its signing key. */
interface CertificateAwareSignatureBackend extends SignatureBackend
{
    public function certificateChain(): CertificateChain;
}
