<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;

/** ISO/TS 32004:2024 clauses 6.2-6.6: complete PDF MAC AuthenticatedData token. */
final readonly class PdfMacTokenComposer
{
    private string $macKey;
    private string $wrappedKey;
    private FileEncryptionKey $kek;
    private string $fileKeyHash;
    private string $kdfSalt;

    public function __construct(FileEncryptionKey $fileKey, string $kdfSalt, RandomSource $random, public SignatureDigestMethod $digest = SignatureDigestMethod::SHA256)
    {
        if (strlen($kdfSalt) !== 32 || !in_array($digest, [SignatureDigestMethod::SHA256, SignatureDigestMethod::SHA384, SignatureDigestMethod::SHA512, SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512], true)) {
            throw new \InvalidArgumentException('PDF MAC requires 32-byte KDFSalt and a TS 32004 Table 8 digest.');
        }
        $this->macKey = $random->bytes(32);
        if (strlen($this->macKey) !== 32) {
            throw new \UnexpectedValueException('PDF MAC requires a fresh 256-bit HMAC key.');
        }
        $this->kek = new FileEncryptionKey(hash_hkdf('sha256', $fileKey->bytes(), 32, 'PDFMAC', $kdfSalt));
        $this->fileKeyHash = hash('sha256', $fileKey->bytes(), true);
        $this->kdfSalt = $kdfSalt;
        $this->wrappedKey = (new AesKeyWrap())->wrap($this->macKey, $this->kek);
    }

    public function validateEncryption(FileEncryption $encryption): void
    {
        $dictionary = $encryption->dictionary();
        $salt = $dictionary->get('KDFSalt');
        $version = $dictionary->get('V');
        $key = $encryption instanceof ModernFileEncryption ? $encryption->key
            : ($encryption instanceof PublicKeyEncryption ? $encryption->filters->selected(new \PDF\Syntax\Object\NameType('DefaultCryptFilter'))?->material->key : null);
        if (!$salt instanceof \PDF\Syntax\Object\StringType || !hash_equals($this->kdfSalt, $salt->value)
            || !$version instanceof \PDF\Syntax\Object\IntegerType || $version->integer() < 5
            || !$key instanceof FileEncryptionKey || !hash_equals($this->fileKeyHash, hash('sha256', $key->bytes(), true))) {
            throw new \LogicException('The PDF MAC composer must bind the actual file encryption key and direct KDFSalt.');
        }
    }

    public function tokenLength(bool $signed = false): int
    {
        return strlen($this->token('', $signed ? '' : null));
    }

    public function token(string $coveredBytes, ?string $signatureValue = null): string
    {
        $der = new CmsDerEncoding();
        $integrity = $der->sequence("\x02\x01\0", $der->octets($this->digest->digest($coveredBytes)), ...($signatureValue === null ? [] : [$der->element(128, $this->digest->digest($signatureValue))]));
        $digestAlgorithm = $der->algorithm($this->digest->oid());
        $macAlgorithm = $der->algorithm('1.2.840.113549.2.9');
        $attributes = $der->set(
            $der->sequence($der->oid('1.2.840.113549.1.9.3'), $der->set($der->oid('1.0.32004.1.0'))),
            $der->sequence($der->oid('1.2.840.113549.1.9.4'), $der->set($der->octets($this->digest->digest($integrity)))),
            $der->sequence($der->oid('1.2.840.113549.1.9.52'), $der->set($der->sequence($digestAlgorithm, $der->element(162, (new SignatureDerReader())->element($macAlgorithm)->value)))),
        );
        $recipient = $der->element(163, "\x02\x01\0" . $der->element(160, $der->oid('1.0.32004.1.1')) . $der->algorithm('2.16.840.1.101.3.4.1.45') . $der->octets($this->wrappedKey));
        $authenticatedData = $der->sequence(
            "\x02\x01\0",
            $der->set($recipient),
            $macAlgorithm,
            $der->element(161, (new SignatureDerReader())->element($digestAlgorithm)->value),
            $der->sequence($der->oid('1.0.32004.1.0'), $der->element(160, $der->octets($integrity))),
            $der->element(162, (new SignatureDerReader())->element($attributes)->value),
            $der->octets(hash_hmac('sha256', $attributes, $this->macKey, true)),
        );
        return $der->sequence($der->oid('1.2.840.113549.1.9.16.1.2'), $der->element(160, $authenticatedData));
    }

    /** Validate CMS authentication and exact document/signature binding; no PKI trust claim. */
    public function verify(string $token, string $coveredBytes, ?string $signatureValue = null): void
    {
        if (strlen($token) > 1048576) {
            throw new \LengthException('The selected PDF MAC verifier limits a CMS token to one MiB.');
        }
        $der = new CmsDerEncoding();
        $reader = new SignatureDerReader();
        $outer = $reader->element($token)->requireTag(48)->children();
        if (count($outer) !== 2 || $outer[0]->encoding !== $der->oid('1.2.840.113549.1.9.16.1.2')) {
            throw new \UnexpectedValueException('A PDF MAC token is an AuthenticatedData ContentInfo.');
        }
        $wrapper = $outer[1]->requireTag(160)->children();
        $data = count($wrapper) === 1 ? $wrapper[0]->requireTag(48)->children() : [];
        if (count($data) !== 7 || $data[0]->encoding !== "\x02\x01\0") {
            throw new \UnexpectedValueException('PDF MAC requires version zero and no originator or unauthenticated attributes.');
        }
        $recipients = $data[1]->requireTag(49)->children();
        $recipient = count($recipients) === 1 ? $recipients[0]->requireTag(163)->children() : [];
        if (count($recipient) !== 4 || $recipient[0]->encoding !== "\x02\x01\0" || $recipient[1]->encoding !== $der->element(160, $der->oid('1.0.32004.1.1')) || $recipient[2]->encoding !== $der->algorithm('2.16.840.1.101.3.4.1.45')) {
            throw new \UnexpectedValueException('PDF MAC requires exactly one PasswordRecipientInfo with pdfMacWrapKdf and AES-256 wrapping.');
        }
        $wrappedKey = $recipient[3]->requireTag(4)->value;
        if (strlen($wrappedKey) !== 40) {
            throw new \UnexpectedValueException('A 256-bit PDF MAC key has exactly forty RFC 3394 wrapped bytes.');
        }
        $macKey = (new AesKeyWrap())->unwrap($wrappedKey, $this->kek);
        if (strlen($macKey) !== 32 || $data[2]->encoding !== $der->algorithm('1.2.840.113549.2.9')) {
            throw new \UnexpectedValueException('PDF MAC requires 256-bit HMAC-SHA256.');
        }
        $digestAlgorithm = $data[3]->requireTag(161);
        $selected = null;
        foreach ([SignatureDigestMethod::SHA256, SignatureDigestMethod::SHA384, SignatureDigestMethod::SHA512, SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512] as $candidate) {
            if ($der->element(48, $digestAlgorithm->value) === $der->algorithm($candidate->oid())) {
                $selected = $candidate;
            }
        }
        if ($selected === null) {
            throw new \UnexpectedValueException('Unsupported PDF MAC digest algorithm.');
        }
        $content = $data[4]->requireTag(48)->children();
        if (count($content) !== 2 || $content[0]->encoding !== $der->oid('1.0.32004.1.0')) {
            throw new \UnexpectedValueException('PDF MAC encapsulates PdfMacIntegrityInfo.');
        }
        $payload = $content[1]->requireTag(160)->children();
        if (count($payload) !== 1) {
            throw new \UnexpectedValueException('PDF MAC has one encapsulated integrity value.');
        }
        $integrityBytes = $payload[0]->requireTag(4)->value;
        $integrity = $reader->element($integrityBytes)->requireTag(48)->children();
        if (count($integrity) !== ($signatureValue === null ? 2 : 3) || $integrity[0]->encoding !== "\x02\x01\0"
            || !hash_equals($selected->digest($coveredBytes), $integrity[1]->requireTag(4)->value)
            || ($signatureValue !== null && !hash_equals($selected->digest($signatureValue), $integrity[2]->requireTag(128)->value))) {
            throw new \UnexpectedValueException('PDF MAC does not bind the supplied exact document byte range and signature value.');
        }
        $attrs = $data[5]->requireTag(162)->children();
        $sorted = array_map(static fn (SignatureDerElement $attribute): string => $attribute->encoding, $attrs);
        sort($sorted, SORT_STRING);
        if (implode('', $sorted) !== $data[5]->value) {
            throw new \UnexpectedValueException('PDF MAC authenticated attributes require canonical DER SET ordering.');
        }
        $expected = [
            $der->oid('1.2.840.113549.1.9.3') => $der->set($der->oid('1.0.32004.1.0')),
            $der->oid('1.2.840.113549.1.9.4') => $der->set($der->octets($selected->digest($integrityBytes))),
            $der->oid('1.2.840.113549.1.9.52') => $der->set($der->sequence($der->algorithm($selected->oid()), $der->element(162, $data[2]->value))),
        ];
        $seen = [];
        foreach ($attrs as $attribute) {
            $fields = $attribute->requireTag(48)->children();
            if (count($fields) !== 2 || isset($seen[$fields[0]->requireTag(6)->encoding])) {
                throw new \UnexpectedValueException('PDF MAC authenticated attributes must be unambiguous.');
            }
            $oid = $fields[0]->encoding;
            $seen[$oid] = true;
            if (isset($expected[$oid]) && $fields[1]->encoding !== $expected[$oid]) {
                throw new \UnexpectedValueException('PDF MAC authenticated attribute value is inconsistent.');
            }
        }
        if (!isset($seen[$der->oid('1.2.840.113549.1.9.3')], $seen[$der->oid('1.2.840.113549.1.9.4')])
            || !hash_equals(hash_hmac('sha256', $der->element(49, $data[5]->value), $macKey, true), $data[6]->requireTag(4)->value)) {
            throw new \UnexpectedValueException('PDF MAC CMS authentication failed.');
        }
    }

    /** @return array{digest:string} */
    public function __debugInfo(): array
    {
        return ['digest' => $this->digest->value];
    }
}
