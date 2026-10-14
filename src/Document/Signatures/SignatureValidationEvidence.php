<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

/** Caller-selected validation policy, including its trust and revocation decisions. */
interface SignatureValidationEvidence
{
    public function assertSuccessful(SignedRevision $signature): void;
}
