<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\SignatureAlgorithmParameters;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureOutputVerifier;
use PDF\Document\Signatures\SignatureVerificationInput;
use PDF\Document\Signatures\VerifiedSignatureFacts;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Syntax\Object\IntegerType;

/** Mathematical validation with the expected credential; deliberately does not assert PKI trust. */
final readonly class OpenSslSignatureOutputVerifier implements SignatureOutputVerifier
{
    public function __construct(private ?string $temporaryDirectory = null)
    {
    }

    public function verify(SignatureVerificationInput $input): VerifiedSignatureFacts
    {
        $facts = new OpenSslSignatureCertificateFacts($input->certificate);
        $public = openssl_pkey_get_public($input->certificate->signer->pem());
        $details = $public === false ? false : openssl_pkey_get_details($public);
        if ($details === false) {
            throw new \InvalidArgumentException('The actual signer public key is unavailable.');
        }
        if ($input->format === SignatureFormat::X509RsaSha1) {
            $raw = (new SignatureDerReader())->element($input->value->bytes)->requireTag(4)->value;
            $parameters = new SignatureAlgorithmParameters($facts->keyAlgorithm(), new IntegerType($details['bits']), SignatureDigestMethod::SHA1);
            $parameters->validateFormat($input->format);
            if (openssl_verify($input->coveredBytes, $raw, $public, OPENSSL_ALGO_SHA1) !== 1) {
                throw new \InvalidArgumentException('The actual PKCS#1 output does not authenticate the supplied byte range.');
            }
            return new VerifiedSignatureFacts($input, $parameters, $facts);
        }
        $inspection = new SignatureCmsInspection($input->value->bytes, $input->format);
        if (!in_array($facts->certificate()->value, $inspection->certificates, true)) {
            throw new \InvalidArgumentException('PDF CMS Contents includes the actual signing certificate.');
        }
        if ($input->format === SignatureFormat::Pkcs7Sha1 && !hash_equals(sha1($input->coveredBytes, true), $inspection->payload ?? '')) {
            throw new \InvalidArgumentException('The CMS SHA1 payload differs from the actual PDF byte-range digest.');
        }
        $parameters = new SignatureAlgorithmParameters($facts->keyAlgorithm(), new IntegerType($details['bits']), $inspection->digest);
        $files = new CmsTemporaryFiles($this->temporaryDirectory);
        try {
            $signature = $files->write($input->value->bytes);
            $detached = $input->format === SignatureFormat::Pkcs7Detached;
            $data = $detached ? $files->write($input->coveredBytes) : $signature;
            $signer = $files->write();
            $certificate = $files->write($input->certificate->signer->pem());
            $flags = OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_NOINTERN | ($detached ? OPENSSL_CMS_DETACHED : 0);
            if (!openssl_cms_verify($data, $flags, $signer, [], $certificate, null, null, $detached ? $signature : null, OPENSSL_ENCODING_DER)) {
                throw new \InvalidArgumentException('The actual CMS output does not authenticate these bytes with the expected credential.');
            }
            $pem = $files->read($signer);
            if (substr_count($pem, '-----BEGIN CERTIFICATE-----') !== 1 || ($verified = openssl_x509_read($pem)) === false || !openssl_x509_export($verified, $actual)) {
                throw new \InvalidArgumentException('Native CMS verification identifies exactly one signer certificate.');
            }
            $encoded = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s/', '', $actual);
            $der = $encoded === null ? false : base64_decode($encoded, true);
            if ($der === false || !hash_equals($facts->certificate()->value, $der)) {
                throw new \InvalidArgumentException('The verified CMS signer differs from the expected certificate.');
            }
        } finally {
            $files->close();
        }
        return new VerifiedSignatureFacts($input, $parameters, $facts);
    }
}
