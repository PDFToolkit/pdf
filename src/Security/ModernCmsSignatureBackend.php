<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Extensions\IsoTechnicalSpecification;
use PDF\Document\Signatures\CatalogSignatureBackend;
use PDF\Document\Signatures\PolicySignatureBackend;
use PDF\Document\Signatures\SignatureAlgorithmParameters;
use PDF\Document\Signatures\SignatureEllipticCurve;
use PDF\Document\Signatures\SignatureEvidenceCapability;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureOutputVerifier;
use PDF\Document\Signatures\SignatureValue;
use PDF\Document\Signatures\SignatureVerificationInput;
use PDF\Document\Signatures\VerifiedSignatureFacts;
use PDF\Document\Signatures\Policies\SignatureCertificateFacts;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureHandlerCapabilities;
use PDF\Document\Signatures\Policies\SignatureKeyAlgorithm;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Detached CMS with explicit digest and named curves; no trust/revocation assertion. */
final readonly class ModernCmsSignatureBackend implements PolicySignatureBackend, SignatureOutputVerifier, CatalogSignatureBackend
{
    public ?SignatureEllipticCurve $curve;
    private SignatureAlgorithmParameters $parameters;

    public function __construct(public OpenSslIdentity $identity, public SignatureDigestMethod $digest, private ModernSignaturePrimitive $primitive = new OpenSslModernSignaturePrimitive(), public NameType $handler = new NameType('Adobe.PPKLite'))
    {
        $details = openssl_pkey_get_details($identity->key());
        if ($details === false) {
            throw new \InvalidArgumentException('The actual signing key parameters are unavailable.');
        }
        $curve = $details['type'] === OPENSSL_KEYTYPE_RSA ? null : (new NamedCurveCertificate($identity->chain->signer))->curve;
        $this->curve = $curve;
        $this->parameters = new SignatureAlgorithmParameters($curve?->keyAlgorithm() ?? SignatureKeyAlgorithm::RSA, new IntegerType($details['bits']), $digest, $curve);
        if ($curve === null && $digest === SignatureDigestMethod::SHAKE256) {
            throw new \InvalidArgumentException('Fixed SHAKE256 is specified for Ed448 signatures, not RSA PKCS#1.');
        }
    }

    public function certificateChain(): CertificateChain
    {
        return $this->identity->chain;
    }

    public function certificateFacts(): SignatureCertificateFacts
    {
        return new OpenSslSignatureCertificateFacts($this->identity->chain);
    }

    public function capabilities(): SignatureHandlerCapabilities
    {
        return new SignatureHandlerCapabilities($this->handler, new NumericType(3), SignatureFormat::Pkcs7Detached);
    }

    public function supportedDigests(SignatureFormat $format): array
    {
        $this->validateFormat($format);
        return [$this->digest];
    }

    public function outputVerifier(): SignatureOutputVerifier
    {
        return $this;
    }

    public function supportsEvidence(SignatureEvidenceCapability $capability): bool
    {
        return false;
    }

    public function validateVersion(EffectivePdfVersion $version, SignatureFormat $format): void
    {
        $this->validateFormat($format);
        $version->require(PdfVersion::PDF20, 'Explicit modern PDF signature algorithms');
        $this->parameters->validateVersion($version, $format);
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        if ($this->curve !== null) {
            IsoTechnicalSpecification::DigitalSignatures->validate($catalog);
        }
        if (in_array($this->digest, [SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512, SignatureDigestMethod::SHAKE256], true)) {
            IsoTechnicalSpecification::HashAlgorithms->validate($catalog);
        }
    }

    public function signWithDigest(string $coveredBytes, SignatureFormat $format, SignatureDigestMethod $digest): SignatureValue
    {
        if ($digest !== $this->digest) {
            throw new \InvalidArgumentException('The requested digest must match the configured modern signature operation.');
        }
        return $this->sign($coveredBytes, $format);
    }

    public function sign(string $coveredBytes, SignatureFormat $format): SignatureValue
    {
        $this->validateFormat($format);
        $der = new CmsDerEncoding();
        $attributes = $this->attributes($coveredBytes);
        $signature = $this->primitive->sign($attributes, $this->identity, $this->digest);
        $signer = $der->sequence("\x02\x01\x01", $this->issuerAndSerial(), $der->algorithm($this->digest->oid()), $der->element(160, (new SignatureDerReader())->element($attributes)->value), $this->signatureAlgorithm(), $der->octets($signature));
        $certificates = [$this->identity->chain->signer->value->value, ...array_map(static fn (X509Certificate $certificate): string => $certificate->value->value, $this->identity->chain->intermediates)];
        sort($certificates, SORT_STRING);
        $signed = $der->sequence("\x02\x01\x01", $der->set($der->algorithm($this->digest->oid())), $der->sequence($der->oid('1.2.840.113549.1.7.1')), $der->element(160, implode('', $certificates)), $der->set($signer));
        $value = new SignatureValue($der->sequence($der->oid('1.2.840.113549.1.7.2'), $der->element(160, $signed)));
        $this->verify(new SignatureVerificationInput($coveredBytes, $value, $format, $this->certificateChain()));
        return $value;
    }

    public function verify(SignatureVerificationInput $input): VerifiedSignatureFacts
    {
        $this->validateFormat($input->format);
        if (!hash_equals($this->identity->chain->signer->value->value, $input->certificate->signer->value->value)) {
            throw new \InvalidArgumentException('The modern verifier binds the expected actual certificate.');
        }
        $der = new CmsDerEncoding();
        $outer = (new SignatureDerReader())->element($input->value->bytes)->requireTag(48)->children();
        if (count($outer) !== 2 || $outer[0]->encoding !== $der->oid('1.2.840.113549.1.7.2')) {
            throw new \InvalidArgumentException('A detached PDF signature requires CMS SignedData.');
        }
        $wrapped = $outer[1]->requireTag(160)->children();
        $data = count($wrapped) === 1 ? $wrapped[0]->requireTag(48)->children() : [];
        if (count($data) !== 5 || $data[0]->encoding !== "\x02\x01\x01" || $data[1]->encoding !== $der->set($der->algorithm($this->digest->oid())) || $data[2]->encoding !== $der->sequence($der->oid('1.2.840.113549.1.7.1'))) {
            throw new \InvalidArgumentException('The detached CMS algorithms and absent eContent must match the selected operation.');
        }
        $certificates = array_map(static fn (SignatureDerElement $certificate): string => $certificate->encoding, $data[3]->requireTag(160)->children());
        if (!in_array($this->identity->chain->signer->value->value, $certificates, true)) {
            throw new \InvalidArgumentException('CMS must contain the actual signer certificate.');
        }
        $signers = $data[4]->requireTag(49)->children();
        $signer = count($signers) === 1 ? $signers[0]->requireTag(48)->children() : [];
        if ((count($signer) !== 6 && count($signer) !== 7) || $signer[0]->encoding !== "\x02\x01\x01" || $signer[1]->encoding !== $this->issuerAndSerial()
            || $signer[2]->encoding !== $der->algorithm($this->digest->oid()) || $signer[4]->encoding !== $this->signatureAlgorithm()) {
            throw new \InvalidArgumentException('CMS requires one exact signer, congruent digest algorithms and its actual signature algorithm.');
        }
        $attributes = $this->attributes($input->coveredBytes);
        if ($signer[3]->requireTag(160)->value !== (new SignatureDerReader())->element($attributes)->value
            || !$this->primitive->verify($attributes, $signer[5]->requireTag(4)->value, $input->certificate->signer, $this->digest)) {
            throw new \InvalidArgumentException('The modern signature does not authenticate the exact byte range and congruent signed attributes.');
        }
        if (isset($signer[6])) {
            $signer[6]->requireTag(161)->children();
        }
        return new VerifiedSignatureFacts($input, $this->parameters, $this->certificateFacts());
    }

    private function attributes(string $bytes): string
    {
        $der = new CmsDerEncoding();
        return $der->set(
            $der->sequence($der->oid('1.2.840.113549.1.9.3'), $der->set($der->oid('1.2.840.113549.1.7.1'))),
            $der->sequence($der->oid('1.2.840.113549.1.9.4'), $der->set($der->octets($this->digest->digest($bytes)))),
        );
    }

    private function issuerAndSerial(): string
    {
        $outer = (new SignatureDerReader())->element($this->identity->chain->signer->value->value)->requireTag(48)->children();
        $tbs = $outer[0]->requireTag(48)->children();
        $offset = $tbs[0]->tag === 160 ? 1 : 0;
        return (new CmsDerEncoding())->sequence($tbs[$offset + 2]->encoding, $tbs[$offset]->encoding);
    }

    private function signatureAlgorithm(): string
    {
        $der = new CmsDerEncoding();
        if ($this->curve === null) {
            return $der->sequence($der->oid('1.2.840.113549.1.1.1'), "\x05\0");
        }
        if ($this->curve->keyAlgorithm() === SignatureKeyAlgorithm::EdDSA) {
            return $der->algorithm($this->curve->oid());
        }
        return $der->algorithm(match ($this->digest) {
            SignatureDigestMethod::SHA256 => '1.2.840.10045.4.3.2', SignatureDigestMethod::SHA384 => '1.2.840.10045.4.3.3', SignatureDigestMethod::SHA512 => '1.2.840.10045.4.3.4',
            SignatureDigestMethod::SHA3_256 => '2.16.840.1.101.3.4.3.10', SignatureDigestMethod::SHA3_384 => '2.16.840.1.101.3.4.3.11', SignatureDigestMethod::SHA3_512 => '2.16.840.1.101.3.4.3.12',
            default => throw new \LogicException('The ECDSA digest is outside the validated curve matrix.'),
        });
    }

    private function validateFormat(SignatureFormat $format): void
    {
        if ($format !== SignatureFormat::Pkcs7Detached) {
            throw new \InvalidArgumentException('This modern CMS backend emits adbe.pkcs7.detached; timestamp and CAdES profiles require their own evidence providers.');
        }
    }
}
