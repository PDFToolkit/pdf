<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;

/** TS32004 Table8, physical p14; RFC5754 §2: only SHA-2 treats absent and NULL parameters alike. */
final readonly class PdfMacDigestAlgorithm
{
    public SignatureDigestMethod $method;

    public function __construct(SignatureDerElement $algorithm)
    {
        $fields = $algorithm->requireTag(48)->children();
        if (count($fields) < 1 || count($fields) > 2) {
            throw new \UnexpectedValueException('A PDF MAC digest AlgorithmIdentifier has its OID and only its permitted parameters.');
        }
        $oid = $fields[0]->requireObjectIdentifier()->encoding;
        $der = new CmsDerEncoding();
        foreach ([SignatureDigestMethod::SHA256, SignatureDigestMethod::SHA384, SignatureDigestMethod::SHA512, SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512] as $candidate) {
            if ($oid !== $der->oid($candidate->oid())) {
                continue;
            }
            if (count($fields) === 2 && (!in_array($candidate, [SignatureDigestMethod::SHA256, SignatureDigestMethod::SHA384, SignatureDigestMethod::SHA512], true) || $fields[1]->encoding !== "\x05\x00")) {
                throw new \UnexpectedValueException('PDF MAC SHA-2 parameters are absent or NULL; SHA-3 parameters are absent.');
            }
            $this->method = $candidate;
            return;
        }
        throw new \UnexpectedValueException('Unsupported PDF MAC digest algorithm.');
    }
}
