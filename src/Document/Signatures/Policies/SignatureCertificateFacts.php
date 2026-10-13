<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\DataStructure\ByteString;

/** Supplied by an external X509 parser/validator. These are parsed facts, never a trust=true switch. */
interface SignatureCertificateFacts
{
    public function certificate(): ByteString;
    /** @return list<ByteString> Validated direct/indirect issuer certificates in the actual chain. */
    public function issuers(): array;
    /** @return list<CertificatePolicyOid> */
    public function policies(): array;
    public function containsSubject(CertificateSubjectName $required): bool;
    public function keyUsage(): ?CertificateKeyUsage;
    public function keyAlgorithm(): SignatureKeyAlgorithm;
}
