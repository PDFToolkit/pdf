<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\SignatureEllipticCurve;

/** RFC 5480 namedCurve only, with RFC 8410 parameter-free EdDSA identifiers. */
final readonly class NamedCurveCertificate
{
    public SignatureEllipticCurve $curve;

    public function __construct(X509Certificate $certificate)
    {
        $outer = (new SignatureDerReader())->element($certificate->value->value)->requireTag(48)->children();
        $tbs = ($outer[0] ?? throw new \InvalidArgumentException('Incomplete X.509 certificate.'))->requireTag(48)->children();
        $spkiIndex = isset($tbs[0]) && $tbs[0]->tag === 160 ? 6 : 5;
        $spki = ($tbs[$spkiIndex] ?? throw new \InvalidArgumentException('Certificate has no SubjectPublicKeyInfo.'))->requireTag(48)->children();
        $algorithm = ($spki[0] ?? throw new \InvalidArgumentException('Certificate has no public key algorithm.'))->requireTag(48)->children();
        $der = new CmsDerEncoding();
        foreach (SignatureEllipticCurve::cases() as $curve) {
            $eddsa = in_array($curve, [SignatureEllipticCurve::Ed25519, SignatureEllipticCurve::Ed448], true);
            if (($eddsa && count($algorithm) === 1 && $algorithm[0]->encoding === $der->oid($curve->oid()))
                || (!$eddsa && count($algorithm) === 2 && $algorithm[0]->encoding === $der->oid('1.2.840.10045.2.1') && $algorithm[1]->encoding === $der->oid($curve->oid()))) {
                $this->curve = $curve;
                return;
            }
        }
        throw new \InvalidArgumentException('The certificate must identify a supported named curve; explicit and implicit curve parameters are prohibited.');
    }
}
