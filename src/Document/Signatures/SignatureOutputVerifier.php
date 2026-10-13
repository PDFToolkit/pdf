<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** Verify the mathematics and encoding of these input/output bytes, or throw; not a caller success flag. */
interface SignatureOutputVerifier
{
    public function verify(SignatureVerificationInput $input): VerifiedSignatureFacts;
}
