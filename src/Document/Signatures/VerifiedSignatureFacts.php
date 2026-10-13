<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\SignatureCertificateFacts;

/** Produced by a selected verifier after checking the actual signature. It does not declare PKI trust. */
final readonly class VerifiedSignatureFacts
{
    private string $inputHash;
    private string $outputHash;
    private SignatureFormat $format;

    public function __construct(SignatureVerificationInput $input, public SignatureAlgorithmParameters $algorithms, public SignatureCertificateFacts $certificate)
    {
        if (!hash_equals($input->certificate->signer->value->value, $certificate->certificate()->value)) {
            throw new \InvalidArgumentException('Verified facts identify the actual expected signing certificate.');
        }
        $this->inputHash = hash('sha256', $input->coveredBytes, true);
        $this->outputHash = hash('sha256', $input->value->bytes, true);
        $this->format = $input->format;
    }

    public function validateBinding(SignatureVerificationInput $input): void
    {
        if ($this->format !== $input->format || !hash_equals($this->inputHash, hash('sha256', $input->coveredBytes, true))
            || !hash_equals($this->outputHash, hash('sha256', $input->value->bytes, true))
            || !hash_equals($input->certificate->signer->value->value, $this->certificate->certificate()->value)) {
            throw new \InvalidArgumentException('Signature verification facts belong to these exact input/output bytes and credential.');
        }
    }
}
