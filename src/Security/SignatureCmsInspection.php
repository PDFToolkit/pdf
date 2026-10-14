<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\SignatureFormat;

/** Bounded inspection of the single-signer DER envelope emitted by the native PHP CMS backend. */
final readonly class SignatureCmsInspection
{
    public SignatureDigestMethod $digest;
    public ?string $payload;
    /** @var list<string> */
    public array $certificates;

    public function __construct(string $der, SignatureFormat $format)
    {
        if ($format === SignatureFormat::X509RsaSha1) {
            throw new \InvalidArgumentException('A CMS inspection requires one of the two CMS SubFilter formats.');
        }
        $root = (new SignatureDerReader())->element($der)->requireTag(48)->children();
        if (count($root) !== 2 || $root[0]->requireTag(6)->value !== "\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02") {
            throw new \InvalidArgumentException('PDF CMS signatures require a SignedData ContentInfo.');
        }
        $wrapped = $root[1]->requireTag(160)->children();
        if (count($wrapped) !== 1) {
            throw new \InvalidArgumentException('CMS SignedData has one explicit envelope.');
        }
        $data = $wrapped[0]->requireTag(48)->children();
        if (count($data) < 4) {
            throw new \InvalidArgumentException('CMS SignedData is incomplete.');
        }
        $content = $data[2]->requireTag(48)->children();
        if ($content === [] || $content[0]->requireTag(6)->value !== "\x2a\x86\x48\x86\xf7\x0d\x01\x07\x01") {
            throw new \InvalidArgumentException('The PDF CMS payload has ContentInfo type Data.');
        }
        $payload = null;
        if (count($content) > 1) {
            if (count($content) !== 2) {
                throw new \InvalidArgumentException('CMS encapsulated content is ambiguous.');
            }
            $value = $content[1]->requireTag(160)->children();
            if (count($value) !== 1) {
                throw new \InvalidArgumentException('CMS content has one OCTET STRING.');
            }
            $payload = $value[0]->requireTag(4)->value;
        }
        if ($format === SignatureFormat::Pkcs7Detached && $payload !== null) {
            throw new \InvalidArgumentException('Detached PDF signatures omit CMS eContent.');
        }
        if ($format === SignatureFormat::Pkcs7Sha1 && $payload === null) {
            throw new \InvalidArgumentException('The SHA1 wrapper encapsulates the document byte-range digest.');
        }
        $this->payload = $payload;
        $certificates = [];
        foreach (array_slice($data, 3, -1) as $optional) {
            if ($optional->tag === 160) {
                foreach ($optional->children() as $certificate) {
                    if ($certificate->tag === 48) {
                        $certificates[] = $certificate->encoding;
                    }
                }
            }
        }
        $this->certificates = $certificates;
        $signers = $data[array_key_last($data)]->requireTag(49)->children();
        if (count($signers) !== 1) {
            throw new \LogicException('The native verifier requires exactly one CMS signer; select an external verifier for other envelopes.');
        }
        $signer = $signers[0]->requireTag(48)->children();
        if (count($signer) < 5) {
            throw new \InvalidArgumentException('The CMS SignerInfo is incomplete.');
        }
        $algorithm = $signer[2]->requireTag(48)->children();
        if ($algorithm === []) {
            throw new \InvalidArgumentException('CMS SignerInfo provides its actual digest algorithm.');
        }
        $this->digest = match (bin2hex($algorithm[0]->requireTag(6)->value)) {
            '2b0e03021a' => SignatureDigestMethod::SHA1,
            '608648016503040201' => SignatureDigestMethod::SHA256,
            '608648016503040202' => SignatureDigestMethod::SHA384,
            '608648016503040203' => SignatureDigestMethod::SHA512,
            '2b24030201' => SignatureDigestMethod::RIPEMD160,
            '608648016503040208' => SignatureDigestMethod::SHA3_256,
            '608648016503040209' => SignatureDigestMethod::SHA3_384,
            '60864801650304020a' => SignatureDigestMethod::SHA3_512,
            '60864801650304020c' => SignatureDigestMethod::SHAKE256,
            default => throw new \InvalidArgumentException('The CMS signer digest is outside ISO Table257.'),
        };
    }
}
