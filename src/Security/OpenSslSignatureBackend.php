<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\PolicySignatureBackend;
use PDF\Document\Signatures\SignatureOutputVerifier;
use PDF\Document\Signatures\SignatureVerificationInput;
use PDF\Document\Signatures\SignatureEvidenceCapability;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureValue;
use PDF\Document\Signatures\SignatureAlgorithmParameters;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureKeyAlgorithm;
use PDF\Document\Signatures\Policies\SignatureHandlerCapabilities;
use PDF\Document\Signatures\Policies\SignatureCertificateFacts;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Explicit mathematical signature backend. Trust, revocation and timestamp policy remain caller-owned. */
final readonly class OpenSslSignatureBackend implements PolicySignatureBackend
{
    public function __construct(
        public OpenSslIdentity $identity,
        private ?string $temporaryDirectory = null,
        private DerOctetString $octetString = new DerOctetString(),
        public NameType $handler = new NameType('Adobe.PPKLite')
    ) {
    }

    public function certificateChain(): CertificateChain
    {
        return $this->identity->chain;
    }

    public function sign(string $coveredBytes, SignatureFormat $format): SignatureValue
    {
        $this->algorithmParameters($format)->validateFormat($format);
        if ($format === SignatureFormat::X509RsaSha1) {
            if (!openssl_sign($coveredBytes, $signature, $this->identity->key(), OPENSSL_ALGO_SHA1)) {
                throw new \RuntimeException('Native PKCS#1 signing failed.');
            }
            return $this->verifiedOutput($coveredBytes, $format, new SignatureValue($this->octetString->encode($signature)));
        }
        $files = new CmsTemporaryFiles($this->temporaryDirectory);
        try {
            $input = $files->write($format === SignatureFormat::Pkcs7Sha1 ? sha1($coveredBytes, true) : $coveredBytes);
            $output = $files->write();
            $chain = $this->identity->chain->intermediates;
            $chainPath = $chain === [] ? null : $files->write(implode('', array_map(static fn (X509Certificate $cert) => $cert->pem(), $chain)));
            $flags = OPENSSL_CMS_BINARY | ($format === SignatureFormat::Pkcs7Detached ? OPENSSL_CMS_DETACHED : 0);
            if (!openssl_cms_sign(
                $input,
                $output,
                $this->identity->certificate,
                $this->identity->key(),
                null,
                $flags,
                OPENSSL_ENCODING_DER,
                $chainPath
            )) {
                throw new \RuntimeException('Native DER CMS signing failed.');
            }
            return $this->verifiedOutput($coveredBytes, $format, new SignatureValue($files->read($output)));
        } finally {
            $files->close();
        }
    }

    public function capabilities(): SignatureHandlerCapabilities
    {
        $parameters = $this->algorithmParameters(SignatureFormat::Pkcs7Detached);
        $formats = [SignatureFormat::Pkcs7Detached, SignatureFormat::Pkcs7Sha1];
        if ($parameters->keyAlgorithm === SignatureKeyAlgorithm::RSA) {
            $formats[] = SignatureFormat::X509RsaSha1;
        }
        return new SignatureHandlerCapabilities($this->handler, new NumericType(3), ...$formats);
    }

    public function supportedDigests(SignatureFormat $format): array
    {
        $parameters = $this->algorithmParameters($format);
        $parameters->validateFormat($format);
        return [$parameters->digest];
    }

    public function certificateFacts(): SignatureCertificateFacts
    {
        return new OpenSslSignatureCertificateFacts($this->identity->chain);
    }
    public function outputVerifier(): SignatureOutputVerifier
    {
        return new OpenSslSignatureOutputVerifier($this->temporaryDirectory);
    }

    public function supportsEvidence(SignatureEvidenceCapability $capability): bool
    {
        return false;
    }

    public function signWithDigest(string $coveredBytes, SignatureFormat $format, SignatureDigestMethod $digest): SignatureValue
    {
        if (!in_array($digest, $this->supportedDigests($format), true)) {
            throw new \LogicException('The PHP native backend cannot configure a different CMS digest or attach signature evidence.');
        }
        return $this->sign($coveredBytes, $format);
    }

    private function verifiedOutput(string $coveredBytes, SignatureFormat $format, SignatureValue $value): SignatureValue
    {
        $input = new SignatureVerificationInput($coveredBytes, $value, $format, $this->identity->chain);
        $actual = $this->outputVerifier()->verify($input);
        $actual->validateBinding($input);
        $expected = $this->algorithmParameters($format);
        if ($actual->algorithms->digest !== $expected->digest || $actual->algorithms->keyAlgorithm !== $expected->keyAlgorithm || $actual->algorithms->keyBits->integer() !== $expected->keyBits->integer()) {
            throw new \RuntimeException('Native OpenSSL emitted signature algorithms different from its declared capabilities.');
        }
        return $value;
    }

    public function validateVersion(EffectivePdfVersion $version, SignatureFormat $format): void
    {
        $this->algorithmParameters($format)->validateVersion($version, $format);
    }

    /** Native PKCS#1 is SHA1; PHP CMS_sign has no digest-selection argument and uses SHA256 for RSA/DSA. */
    public function algorithmParameters(SignatureFormat $format): SignatureAlgorithmParameters
    {
        $details = openssl_pkey_get_details($this->identity->key());
        if ($details === false) {
            throw new \RuntimeException('The actual signing key parameters are unavailable.');
        }
        $algorithm = match ($details['type']) {
            OPENSSL_KEYTYPE_RSA => SignatureKeyAlgorithm::RSA,
            OPENSSL_KEYTYPE_DSA => SignatureKeyAlgorithm::DSA,
            default => SignatureKeyAlgorithm::Other,
        };
        return new SignatureAlgorithmParameters(
            $algorithm,
            new IntegerType($details['bits']),
            $format === SignatureFormat::X509RsaSha1 ? SignatureDigestMethod::SHA1 : SignatureDigestMethod::SHA256
        );
    }
}
