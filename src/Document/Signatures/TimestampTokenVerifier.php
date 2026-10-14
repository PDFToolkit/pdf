<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\CertificateChain;

/** Mathematical authority binding; trust, validity-time and revocation policy remain explicit. */
interface TimestampTokenVerifier
{
    public function verify(TimestampToken $token, TimestampRequest $request): void;
    public function certificateChain(): CertificateChain;
}
