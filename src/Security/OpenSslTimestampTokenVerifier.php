<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\TimestampRequest;
use PDF\Document\Signatures\TimestampToken;
use PDF\Document\Signatures\TimestampTokenVerifier;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;

/** RFC 3161/5816 CMS, imprint, nonce, ESS certificate binding and critical timestamping EKU. */
final readonly class OpenSslTimestampTokenVerifier implements TimestampTokenVerifier
{
    public function __construct(private CertificateChain $authority, private ?string $temporaryDirectory = null)
    {
        $this->validateEku();
    }

    public function certificateChain(): CertificateChain
    {
        return $this->authority;
    }

    public function verify(TimestampToken $token, TimestampRequest $request): void
    {
        $token->validateRequest($request);
        if (!in_array($this->authority->signer->value->value, $token->certificates, true)) {
            throw new \InvalidArgumentException('The certReq timestamp response includes the exact requested authority certificate.');
        }
        $this->validateAuthorityName($token);
        $attributes = $token->signerInfo[3]->children();
        $seen = [];
        $ess = false;
        $der = new CmsDerEncoding();
        foreach ($attributes as $attribute) {
            $fields = $attribute->requireTag(48)->children();
            if (count($fields) !== 2 || isset($seen[$fields[0]->requireTag(6)->encoding])) {
                throw new \InvalidArgumentException('Timestamp signed attributes are unambiguous.');
            }
            $oid = $fields[0]->encoding;
            $seen[$oid] = true;
            $values = $fields[1]->requireTag(49)->children();
            if ($oid === $der->oid('1.2.840.113549.1.9.3') && (count($values) !== 1 || $values[0]->encoding !== $der->oid('1.2.840.113549.1.9.16.1.4'))) {
                throw new \InvalidArgumentException('Timestamp signed contentType is TSTInfo.');
            }
            if ($oid === $der->oid('1.2.840.113549.1.9.4') && (count($values) !== 1 || !hash_equals($token->signingDigest->digest($token->tstInfo), $values[0]->requireTag(4)->value))) {
                throw new \InvalidArgumentException('Timestamp signed messageDigest binds the exact TSTInfo octets.');
            }
            if (in_array($oid, [$der->oid('1.2.840.113549.1.9.16.2.12'), $der->oid('1.2.840.113549.1.9.16.2.47')], true)) {
                if (count($values) !== 1) {
                    throw new \InvalidArgumentException('Timestamp signing-certificate attributes have one value.');
                }
                $signing = $values[0]->requireTag(48)->children();
                if (count($signing) < 1 || count($signing) > 2) {
                    throw new \InvalidArgumentException('Timestamp ESS attributes contain certificates and optional policies.');
                }
                $certificates = $signing[0]->requireTag(48)->children();
                $certificate = ($certificates[0] ?? throw new \InvalidArgumentException('Timestamp ESS signer certificate is required.'))->requireTag(48)->children();
                $digest = $oid === $der->oid('1.2.840.113549.1.9.16.2.12') ? SignatureDigestMethod::SHA1 : SignatureDigestMethod::SHA256;
                if ($oid === $der->oid('1.2.840.113549.1.9.16.2.47') && isset($certificate[0]) && $certificate[0]->tag === 48) {
                    $digest = $token->digestAlgorithm(array_shift($certificate));
                }
                if (count($certificate) < 1 || count($certificate) > 2) {
                    throw new \InvalidArgumentException('Timestamp ESS certificate identifiers contain a hash and optional issuerSerial.');
                }
                $hash = $certificate[0]->requireTag(4)->value;
                if (!hash_equals($digest->digest($this->authority->signer->value->value), $hash)) {
                    throw new \InvalidArgumentException('Timestamp ESS signing certificate differs from the expected authority.');
                }
                if (isset($certificate[1])) {
                    $this->validateEssIssuerSerial($certificate[1]);
                }
                $ess = true;
            }
        }
        if (!$ess || !isset($seen[$der->oid('1.2.840.113549.1.9.3')], $seen[$der->oid('1.2.840.113549.1.9.4')])) {
            throw new \InvalidArgumentException('A timestamp requires its contentType, messageDigest and ESS signer-certificate attributes.');
        }
        $files = new CmsTemporaryFiles($this->temporaryDirectory);
        try {
            $signature = $files->write($token->bytes);
            $certificate = $files->write($this->authority->signer->pem());
            $signer = $files->write();
            $content = $files->write();
            if (!openssl_cms_verify($signature, OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_NOINTERN, $signer, [], $certificate, $content, null, null, OPENSSL_ENCODING_DER)
                || !hash_equals($token->tstInfo, $files->read($content))) {
                throw new \InvalidArgumentException('Timestamp CMS does not authenticate TSTInfo with the expected authority key.');
            }
        } finally {
            $files->close();
        }
    }

    private function validateEku(): void
    {
        $outer = (new SignatureDerReader())->element($this->authority->signer->value->value)->requireTag(48)->children();
        $tbs = $outer[0]->requireTag(48)->children();
        $der = new CmsDerEncoding();
        $found = false;
        foreach ($tbs as $field) {
            if ($field->tag !== 163) {
                continue;
            }
            $wrapped = $field->children();
            $extensions = count($wrapped) === 1 ? $wrapped[0]->requireTag(48)->children() : [];
            foreach ($extensions as $extension) {
                $parts = $extension->requireTag(48)->children();
                if (isset($parts[0]) && $parts[0]->encoding === $der->oid('2.5.29.37')) {
                    if ($found || count($parts) !== 3 || $parts[1]->encoding !== "\x01\x01\xff" || $parts[2]->requireTag(4)->value !== $der->sequence($der->oid('1.3.6.1.5.5.7.3.8'))) {
                        throw new \InvalidArgumentException('The TSA certificate requires a critical EKU containing only id-kp-timeStamping.');
                    }
                    $found = true;
                }
            }
        }
        if (!$found) {
            throw new \InvalidArgumentException('The expected TSA certificate has no critical timestamping EKU.');
        }
    }

    private function validateAuthorityName(TimestampToken $token): void
    {
        $name = $token->authorityName;
        if ($name === null) {
            return;
        }
        $outer = (new SignatureDerReader())->element($this->authority->signer->value->value)->requireTag(48)->children();
        $tbs = $outer[0]->requireTag(48)->children();
        $subject = $tbs[$tbs[0]->tag === 160 ? 5 : 4];
        if ($name->tag === 164 && $name->value === $subject->encoding) {
            return;
        }
        foreach ($tbs as $field) {
            if ($field->tag !== 163) {
                continue;
            }
            $wrapped = $field->children();
            foreach (count($wrapped) === 1 ? $wrapped[0]->requireTag(48)->children() : [] as $extension) {
                $parts = $extension->requireTag(48)->children();
                if ($parts === [] || $parts[0]->encoding !== (new CmsDerEncoding())->oid('2.5.29.17')) {
                    continue;
                }
                $value = $parts[array_key_last($parts)]->requireTag(4)->value;
                foreach ((new SignatureDerReader())->element($value)->requireTag(48)->children() as $candidate) {
                    if ($candidate->encoding === $name->encoding) {
                        return;
                    }
                }
            }
        }
        throw new \InvalidArgumentException('The timestamp authority GeneralName must identify the actual signer certificate.');
    }

    private function validateEssIssuerSerial(SignatureDerElement $issuerSerial): void
    {
        $parts = $issuerSerial->requireTag(48)->children();
        $names = isset($parts[0]) ? $parts[0]->requireTag(48)->children() : [];
        $certificate = (new SignatureDerReader())->element($this->authority->signer->value->value)->requireTag(48)->children();
        $tbs = $certificate[0]->requireTag(48)->children();
        $offset = $tbs[0]->tag === 160 ? 1 : 0;
        if (count($parts) !== 2 || count($names) !== 1 || $names[0]->tag !== 164 || $names[0]->value !== $tbs[$offset + 2]->encoding
            || $parts[1]->requireTag(2)->encoding !== $tbs[$offset]->requireTag(2)->encoding) {
            throw new \InvalidArgumentException('Timestamp ESS issuerSerial must identify the actual TSA certificate issuer and serial.');
        }
    }
}
