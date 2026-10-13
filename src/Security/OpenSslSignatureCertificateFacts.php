<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\Policies\CertificateKeyUsage;
use PDF\Document\Signatures\Policies\CertificateSubjectName;
use PDF\Document\Signatures\Policies\SignatureCertificateFacts;
use PDF\Document\Signatures\Policies\SignatureKeyAlgorithm;
use PDF\Syntax\DataStructure\ByteString;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;

/** Parsed credential facts and mathematically verified issuer links; no trust-anchor or validity assertion. */
final readonly class OpenSslSignatureCertificateFacts implements SignatureCertificateFacts
{
    /** @var array<string, string|list<string>> */
    private array $subject;
    /** @var array<string, string> */
    private array $extensions;
    /** @var list<ByteString> */
    private array $verifiedIssuers;
    private SignatureKeyAlgorithm $algorithm;

    public function __construct(public CertificateChain $chain)
    {
        $signer = openssl_x509_read($chain->signer->pem());
        $parsed = $signer === false ? false : openssl_x509_parse($signer, false);
        $public = $signer === false ? false : openssl_pkey_get_public($signer);
        $details = $public === false ? false : openssl_pkey_get_details($public);
        if ($parsed === false || $details === false) {
            throw new \InvalidArgumentException('The actual signing certificate cannot be parsed.');
        }
        $this->subject = $parsed['subject'];
        $this->extensions = $parsed['extensions'] ?? [];
        $this->algorithm = match ($details['type']) {
            OPENSSL_KEYTYPE_RSA => SignatureKeyAlgorithm::RSA,
            OPENSSL_KEYTYPE_DSA => SignatureKeyAlgorithm::DSA,
            default => SignatureKeyAlgorithm::Other,
        };
        $issuers = [];
        $current = $signer;
        $currentParsed = $parsed;
        foreach ($chain->intermediates as $issuer) {
            $certificate = openssl_x509_read($issuer->pem());
            $candidate = $certificate === false ? false : openssl_x509_parse($certificate, false);
            $key = $certificate === false ? false : openssl_pkey_get_public($certificate);
            if ($candidate === false || $key === false || $currentParsed['issuer'] !== $candidate['subject'] || openssl_x509_verify($current, $key) !== 1) {
                throw new \InvalidArgumentException('Issuer facts require a matching name and a mathematically verified certificate link.');
            }
            $issuers[] = new ByteString($issuer->value->value);
            $current = $certificate;
            $currentParsed = $candidate;
        }
        if ($chain->intermediates === [] && $parsed['issuer'] === $parsed['subject'] && openssl_x509_verify($signer, $public) === 1) {
            $issuers[] = $this->certificate();
        }
        $this->verifiedIssuers = $issuers;
    }

    public function certificate(): ByteString
    {
        return new ByteString($this->chain->signer->value->value);
    }
    public function issuers(): array
    {
        return $this->verifiedIssuers;
    }
    public function keyAlgorithm(): SignatureKeyAlgorithm
    {
        return $this->algorithm;
    }
    public function policies(): array
    {
        if (isset($this->extensions['certificatePolicies'])) {
            throw new \LogicException('Native OpenSSL text does not normalize certificate policy OIDs; supply a certificate processor for this constraint.');
        }
        return [];
    }

    public function containsSubject(CertificateSubjectName $required): bool
    {
        $aliases = [
            'CN' => 'commonName', '2.5.4.3' => 'commonName', 'C' => 'countryName', '2.5.4.6' => 'countryName',
            'L' => 'localityName', '2.5.4.7' => 'localityName', 'ST' => 'stateOrProvinceName', '2.5.4.8' => 'stateOrProvinceName',
            'O' => 'organizationName', '2.5.4.10' => 'organizationName', 'OU' => 'organizationalUnitName', '2.5.4.11' => 'organizationalUnitName',
            'emailAddress' => 'emailAddress', '1.2.840.113549.1.9.1' => 'emailAddress',
        ];
        foreach ($required->entries() as $entry) {
            $key = $aliases[$entry->key->value] ?? $entry->key->value;
            $actual = $this->subject[$key] ?? null;
            if ($actual === null || !$entry->value instanceof StringType || !in_array((new \PDF\Syntax\DataStructure\TextStringDecoder())->decode($entry->value), is_array($actual) ? $actual : [$actual], true)) {
                return false;
            }
        }
        return true;
    }

    public function keyUsage(): ?CertificateKeyUsage
    {
        $value = $this->extensions['keyUsage'] ?? null;
        if ($value === null) {
            return null;
        }
        $names = ['Digital Signature', 'Non Repudiation', 'Key Encipherment', 'Data Encipherment', 'Key Agreement', 'Certificate Sign', 'CRL Sign', 'Encipher Only', 'Decipher Only'];
        $bits = 0;
        foreach (explode(',', $value) as $name) {
            $index = array_search(trim($name), $names, true);
            if ($index === false) {
                throw new \LogicException('The actual key-usage extension cannot be normalized by this OpenSSL processor.');
            }
            $bits |= 1 << $index;
        }
        return new CertificateKeyUsage(new IntegerType($bits));
    }
}
