<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\CertificateAwareSignatureBackend;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureValue;
use PDF\Document\Signatures\SignatureAlgorithmParameters;
use PDF\Document\Signatures\VersionedSignatureBackend;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureKeyAlgorithm;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\Object\IntegerType;

/** Explicit mathematical signature backend. Trust, revocation and timestamp policy remain caller-owned. */
final readonly class OpenSslSignatureBackend implements CertificateAwareSignatureBackend, VersionedSignatureBackend
{
    public function __construct(
        public OpenSslIdentity $identity,
        private ?string $temporaryDirectory = null,
        private DerOctetString $octetString = new DerOctetString()
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
            return new SignatureValue($this->octetString->encode($signature));
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
            return new SignatureValue($files->read($output));
        } finally {
            $files->close();
        }
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
