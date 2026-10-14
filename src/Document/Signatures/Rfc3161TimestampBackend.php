<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureKeyAlgorithm;
use PDF\Security\CertificateChain;
use PDF\Security\NamedCurveCertificate;
use PDF\Security\OpenSslSignatureCertificateFacts;
use PDF\Security\RandomSource;
use PDF\Security\SystemRandomSource;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\IntegerType;

/** Native byte-range timestamps: provider and authority verifier are explicitly supplied. */
final readonly class Rfc3161TimestampBackend implements VersionedSignatureBackend, CertificateAwareSignatureBackend, SignatureOutputVerifier, CatalogSignatureBackend
{
    public function __construct(private TimestampTokenProvider $provider, private TimestampTokenVerifier $verifier, private SignatureDigestMethod $digest = SignatureDigestMethod::SHA256, private ?string $policy = null, private RandomSource $random = new SystemRandomSource(), private SignatureDigestMethod $authorityDigest = SignatureDigestMethod::SHA256)
    {
    }

    public function validateCatalog(\PDF\Syntax\Object\DictionaryType $catalog): void
    {
        foreach ([$this->digest, $this->authorityDigest] as $digest) {
            if (str_starts_with($digest->value, 'SHA3') || $digest === SignatureDigestMethod::SHAKE256) {
                \PDF\Document\Extensions\IsoTechnicalSpecification::HashAlgorithms->validate($catalog);
            }
        }
        $public = openssl_pkey_get_public($this->certificateChain()->signer->pem());
        $details = $public === false ? false : openssl_pkey_get_details($public);
        if ($details !== false && !in_array($details['type'], [OPENSSL_KEYTYPE_RSA, OPENSSL_KEYTYPE_DSA], true)) {
            \PDF\Document\Extensions\IsoTechnicalSpecification::DigitalSignatures->validate($catalog);
        }
    }

    public function certificateChain(): CertificateChain
    {
        return $this->verifier->certificateChain();
    }

    public function validateVersion(EffectivePdfVersion $version, SignatureFormat $format): void
    {
        if ($format !== SignatureFormat::Rfc3161) {
            throw new \InvalidArgumentException('A timestamp backend emits only ETSI.RFC3161.');
        }
        $version->require(PdfVersion::PDF20, 'Document timestamps');
    }

    public function sign(string $coveredBytes, SignatureFormat $format): SignatureValue
    {
        if ($format !== SignatureFormat::Rfc3161) {
            throw new \InvalidArgumentException('A timestamp backend requires the document timestamp SubFilter.');
        }
        $nonce = $this->random->bytes(16);
        if (strlen($nonce) !== 16) {
            throw new \UnexpectedValueException('Timestamp nonce generation requires sixteen random bytes.');
        }
        $request = new TimestampRequest($this->digest, $this->digest->digest($coveredBytes), $this->policy, ltrim($nonce, "\0") ?: "\0");
        $token = $this->provider->request($request);
        if ($token->signingDigest !== $this->authorityDigest) {
            throw new \InvalidArgumentException('The authority token must use the configured signing digest.');
        }
        $this->verifier->verify($token, $request);
        return new SignatureValue($token->bytes);
    }

    public function verify(SignatureVerificationInput $input): VerifiedSignatureFacts
    {
        if ($input->format !== SignatureFormat::Rfc3161 || !hash_equals($input->certificate->signer->value->value, $this->certificateChain()->signer->value->value)) {
            throw new \InvalidArgumentException('Timestamp output verification pins its actual format and authority certificate.');
        }
        $token = new TimestampToken($input->value->bytes);
        if ($token->signingDigest !== $this->authorityDigest) {
            throw new \InvalidArgumentException('The authority token must use the configured signing digest.');
        }
        $this->verifier->verify($token, new TimestampRequest($this->digest, $this->digest->digest($input->coveredBytes), $this->policy, $token->nonce));
        $facts = new OpenSslSignatureCertificateFacts($this->certificateChain());
        $public = openssl_pkey_get_public($this->certificateChain()->signer->pem());
        $details = $public === false ? false : openssl_pkey_get_details($public);
        if ($details === false) {
            throw new \InvalidArgumentException('The timestamp authority public key is unavailable.');
        }
        $curve = in_array($facts->keyAlgorithm(), [SignatureKeyAlgorithm::ECDSA, SignatureKeyAlgorithm::EdDSA], true) ? (new NamedCurveCertificate($this->certificateChain()->signer))->curve : null;
        return new VerifiedSignatureFacts($input, new SignatureAlgorithmParameters($facts->keyAlgorithm(), new IntegerType($details['bits']), $token->signingDigest, $curve), $facts);
    }
}
