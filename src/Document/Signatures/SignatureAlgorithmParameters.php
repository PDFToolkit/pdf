<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureKeyAlgorithm;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\IntegerType;

/** ISO Table257, PDF page484: explicitly normalized signature algorithms and key-size limits. */
final readonly class SignatureAlgorithmParameters
{
    public function __construct(public SignatureKeyAlgorithm $keyAlgorithm, public IntegerType $keyBits, public SignatureDigestMethod $digest, public ?SignatureEllipticCurve $curve = null)
    {
        if ($keyAlgorithm === SignatureKeyAlgorithm::Other) {
            throw new \InvalidArgumentException('The three ISO PDF1.7 signature formats normalize only RSA and DSA keys.');
        }
        if ($keyBits->integer() < 1 || $keyBits->integer() > 4096) {
            throw new \InvalidArgumentException('ISO PDF1.7 signature keys must have 1 through4096 bits; larger keys are not normalized.');
        }
        if (in_array($keyAlgorithm, [SignatureKeyAlgorithm::ECDSA, SignatureKeyAlgorithm::EdDSA], true)) {
            if ($curve === null || $curve->keyAlgorithm() !== $keyAlgorithm) {
                throw new \InvalidArgumentException('Elliptic-curve signatures identify their actual named curve.');
            }
            $curve->validate($digest);
        } elseif ($curve !== null) {
            throw new \InvalidArgumentException('A named elliptic curve cannot describe an RSA or DSA key.');
        }
    }

    public function validateFormat(SignatureFormat $format): void
    {
        if ($format === SignatureFormat::X509RsaSha1 && $this->keyAlgorithm !== SignatureKeyAlgorithm::RSA) {
            throw new \InvalidArgumentException('adbe.x509.rsa_sha1 requires an RSA private key.');
        }
        if ($this->curve !== null && $format !== SignatureFormat::Pkcs7Detached) {
            throw new \InvalidArgumentException('Elliptic-curve signatures require the detached CMS SubFilter.');
        }
    }

    public function validateVersion(EffectivePdfVersion $version, SignatureFormat $format): void
    {
        $this->validateFormat($format);
        if ($this->curve !== null) {
            $version->require(PdfVersion::PDF20, 'Elliptic-curve PDF signatures');
        }
        $version->require(PdfVersion::PDF13, 'Public-key signatures');
        if ($this->keyAlgorithm === SignatureKeyAlgorithm::DSA) {
            $version->require(PdfVersion::PDF16, 'DSA signatures up to4096 bits');
        } elseif ($this->keyBits->integer() > 1024) {
            $version->require(PdfVersion::PDF15, 'RSA signatures above1024 bits');
        }
        $minimum = match ($this->digest) {
            SignatureDigestMethod::SHA1 => PdfVersion::PDF13,
            SignatureDigestMethod::SHA256 => PdfVersion::PDF16,
            SignatureDigestMethod::SHA384, SignatureDigestMethod::SHA512, SignatureDigestMethod::RIPEMD160 => PdfVersion::PDF17,
            SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512, SignatureDigestMethod::SHAKE256 => PdfVersion::PDF20,
        };
        // Table257 note b permits a different digest for SignedData even when its payload is SHA1(ByteRange).
        $version->require($minimum, 'Signature digest ' . $this->digest->value);
    }
}
