<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Security\CmsDerEncoding;
use PDF\Security\SignatureDerElement;
use PDF\Security\SignatureDerReader;

/** Parsed RFC 3161/5816 token; mathematical validation is a separate explicit operation. */
final readonly class TimestampToken
{
    public SignatureDigestMethod $digest;
    public SignatureDigestMethod $signingDigest;
    public string $messageImprint;
    public string $policyEncoding;
    public string $generationTime;
    /** Unsigned big-endian bytes: native integers cannot represent every 160-bit TSA serial. */
    public string $serialNumber;
    public ?string $nonce;
    public ?TimestampAccuracy $accuracy;
    public bool $ordering;
    public ?SignatureDerElement $authorityName;
    /** @var list<TimestampExtension> */
    public array $extensions;
    public string $tstInfo;
    /** @var list<string> DER X.509 certificates actually returned by the authority. */
    public array $certificates;
    /** @var list<SignatureDerElement> */
    public array $signerInfo;

    public function __construct(public string $bytes)
    {
        if (strlen($bytes) > 1048576) {
            throw new \LengthException('The selected timestamp processor limits a token to one MiB.');
        }
        $reader = new SignatureDerReader();
        $der = new CmsDerEncoding();
        $outer = $reader->element($bytes)->requireTag(48)->children();
        if (count($outer) !== 2 || $outer[0]->encoding !== $der->oid('1.2.840.113549.1.7.2')) {
            throw new \InvalidArgumentException('A timestamp token is a CMS SignedData ContentInfo.');
        }
        $wrapped = $outer[1]->requireTag(160)->children();
        $data = count($wrapped) === 1 ? $wrapped[0]->requireTag(48)->children() : [];
        if (count($data) < 4 || count($data) > 6 || !in_array($data[0]->encoding, ["\x02\x01\x03", "\x02\x01\x04", "\x02\x01\x05"], true)) {
            throw new \InvalidArgumentException('The timestamp CMS structure is incomplete.');
        }
        $certificates = [];
        $lastOptional = -1;
        foreach (array_slice($data, 3, -1) as $field) {
            $order = match ($field->tag) {
                160 => 0, 161 => 1, default => throw new \InvalidArgumentException('Timestamp SignedData contains only optional certificates and revocation information.')
            };
            if ($order <= $lastOptional) {
                throw new \InvalidArgumentException('Timestamp SignedData optional fields are unique and in ASN.1 order.');
            }
            $lastOptional = $order;
            if ($field->tag === 160) {
                foreach ($field->children() as $certificate) {
                    if ($certificate->tag === 48) {
                        $certificates[] = $certificate->encoding;
                    }
                }
            }
        }
        $this->certificates = $certificates;
        $content = $data[2]->requireTag(48)->children();
        if (count($content) !== 2 || $content[0]->encoding !== $der->oid('1.2.840.113549.1.9.16.1.4')) {
            throw new \InvalidArgumentException('A timestamp token encapsulates TSTInfo, not ordinary CMS Data.');
        }
        $encapsulated = $content[1]->requireTag(160)->children();
        if (count($encapsulated) !== 1) {
            throw new \InvalidArgumentException('A timestamp has one encapsulated TSTInfo value.');
        }
        $this->tstInfo = $encapsulated[0]->requireTag(4)->value;
        $info = $reader->element($this->tstInfo)->requireTag(48)->children();
        if (count($info) < 5 || $info[0]->encoding !== "\x02\x01\x01") {
            throw new \InvalidArgumentException('Timestamp TSTInfo has version one and its required fields.');
        }
        $this->policyEncoding = $info[1]->requireObjectIdentifier()->encoding;
        $imprint = $info[2]->requireTag(48)->children();
        if (count($imprint) !== 2) {
            throw new \InvalidArgumentException('A timestamp message imprint supplies one algorithm and one hash.');
        }
        $this->digest = $this->digestAlgorithm($imprint[0]);
        $this->messageImprint = $imprint[1]->requireTag(4)->value;
        if (strlen($this->messageImprint) !== strlen($this->digest->digest(''))) {
            throw new \InvalidArgumentException('A timestamp message imprint has the exact algorithm output length.');
        }
        $this->serialNumber = $this->unsignedInteger($info[3]->requireTag(2)->value);
        $time = $info[4]->requireTag(24)->value;
        $calendar = \DateTimeImmutable::createFromFormat('!YmdHis\Z', substr($time, 0, 14) . 'Z', new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (preg_match('/\A[0-9]{14}(?:\.[0-9]*[1-9])?Z\z/', $time) !== 1 || $calendar === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Timestamp generation time is a canonical UTC GeneralizedTime.');
        }
        $this->generationTime = $time;
        $nonce = null;
        $ordering = false;
        $authorityName = null;
        $accuracy = null;
        $extensions = [];
        $lastOrder = -1;
        foreach (array_slice($info, 5) as $optional) {
            $order = match ($optional->tag) {
                48 => 0, 1 => 1, 2 => 2, 160 => 3, 161 => 4, default => throw new \InvalidArgumentException('Unexpected TSTInfo optional field.')
            };
            if ($order <= $lastOrder) {
                throw new \InvalidArgumentException('TSTInfo optional fields are unique and in ASN.1 order.');
            }
            $lastOrder = $order;
            if ($optional->tag === 48) {
                $accuracy = TimestampAccuracy::fromDer($optional);
            }
            if ($optional->tag === 161) {
                $identifiers = [];
                foreach ($optional->children() as $extension) {
                    $value = TimestampExtension::fromDer($extension);
                    if (isset($identifiers[$value->identifier->encoding])) {
                        throw new \InvalidArgumentException('Timestamp extension identifiers are unique.');
                    }
                    $identifiers[$value->identifier->encoding] = true;
                    $extensions[] = $value;
                }
                if ($extensions === []) {
                    throw new \InvalidArgumentException('A present timestamp extensions field is nonempty.');
                }
            }
            if ($optional->tag === 1) {
                if ($optional->encoding !== "\x01\x01\xff") {
                    throw new \InvalidArgumentException('Timestamp ordering uses DER TRUE; its FALSE default is omitted.');
                }
                $ordering = true;
            }
            if ($optional->tag === 160) {
                $name = $optional->children();
                if (count($name) !== 1 || ($name[0]->tag & 192) !== 128) {
                    throw new \InvalidArgumentException('A timestamp authority name contains one GeneralName.');
                }
                $authorityName = $name[0];
            }
            if ($optional->tag === 2) {
                $nonce = $this->unsignedInteger($optional->value);
            }
        }
        $this->nonce = $nonce;
        $this->ordering = $ordering;
        $this->authorityName = $authorityName;
        $this->accuracy = $accuracy;
        $this->extensions = $extensions;
        $signers = $data[array_key_last($data)]->requireTag(49)->children();
        if (count($signers) !== 1) {
            throw new \InvalidArgumentException('A timestamp token contains exactly one signer.');
        }
        $this->signerInfo = $signers[0]->requireTag(48)->children();
        if (count($this->signerInfo) < 6 || count($this->signerInfo) > 7 || $this->signerInfo[3]->tag !== 160) {
            throw new \InvalidArgumentException('A timestamp SignerInfo requires signed attributes and one signature value.');
        }
        $this->signerInfo[5]->requireTag(4);
        $this->signingDigest = $this->digestAlgorithm($this->signerInfo[2]);
        $digests = $data[1]->requireTag(49)->children();
        if (count($digests) !== 1 || $this->digestAlgorithm($digests[0]) !== $this->signingDigest) {
            throw new \InvalidArgumentException('The timestamp SignedData and SignerInfo digest declarations must agree.');
        }
    }

    public function validateRequest(TimestampRequest $request): void
    {
        if ($this->digest !== $request->digest || !hash_equals($this->messageImprint, $request->messageImprint)
            || ($request->policy !== null && $this->policyEncoding !== (new CmsDerEncoding())->oid($request->policy))
            || ($request->nonce !== null && !hash_equals($request->nonce, $this->nonce ?? ''))) {
            throw new \InvalidArgumentException('The timestamp does not bind the exact requested imprint, policy and nonce.');
        }
    }

    private function unsignedInteger(string $bytes): string
    {
        if ($bytes === '' || (ord($bytes[0]) & 128) !== 0 || (strlen($bytes) > 1 && $bytes[0] === "\0" && (ord($bytes[1]) & 128) === 0)) {
            throw new \InvalidArgumentException('Timestamp serials and nonces are minimally encoded nonnegative DER integers.');
        }
        return strlen($bytes) > 1 && $bytes[0] === "\0" ? substr($bytes, 1) : $bytes;
    }

    public function digestAlgorithm(SignatureDerElement $algorithm): SignatureDigestMethod
    {
        $fields = $algorithm->requireTag(48)->children();
        if (count($fields) < 1 || count($fields) > 2 || (isset($fields[1]) && $fields[1]->encoding !== "\x05\0")) {
            throw new \InvalidArgumentException('A timestamp digest has an OID with absent or NULL parameters.');
        }
        foreach (SignatureDigestMethod::cases() as $digest) {
            if ($fields[0]->encoding === (new CmsDerEncoding())->oid($digest->oid())) {
                if (str_starts_with($digest->value, 'SHA3') || $digest === SignatureDigestMethod::SHAKE256) {
                    if (count($fields) !== 1) {
                        throw new \InvalidArgumentException('SHA-3 and SHAKE algorithm identifiers omit parameters.');
                    }
                }
                return $digest;
            }
        }
        throw new \InvalidArgumentException('The timestamp uses an unsupported digest.');
    }
}
