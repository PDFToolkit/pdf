<?php

declare(strict_types=1);

namespace PDF\Security;

/** ISO-TS-32004-2024_sponsored.pdf, 6.2-6.6, PDF pp.13-17: verification allocates no random key. */
final readonly class PdfMacTokenVerifier
{
    private FileEncryptionKey $kek;

    public function __construct(FileEncryptionKey $fileKey, string $kdfSalt, private int $maximumTokenBytes = 1048576)
    {
        if (strlen($kdfSalt) !== 32 || $maximumTokenBytes < 1) {
            throw new \InvalidArgumentException('PDF MAC verification requires a 32-byte KDFSalt and a positive token byte budget.');
        }
        $this->kek = new FileEncryptionKey(hash_hkdf('sha256', $fileKey->bytes(), 32, 'PDFMAC', $kdfSalt));
    }

    /** Validate CMS authentication and exact document/signature binding; no PKI trust claim. */
    public function verify(string $token, string $coveredBytes, ?string $signatureValue = null): void
    {
        if (strlen($token) > $this->maximumTokenBytes) {
            throw new \LengthException('The PDF MAC token exceeds the selected byte budget.');
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
        $selected = (new PdfMacDigestAlgorithm($reader->element($der->element(48, $digestAlgorithm->value))))->method;
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
        ];
        $seen = [];
        foreach ($attrs as $attribute) {
            $fields = $attribute->requireTag(48)->children();
            if (count($fields) !== 2 || isset($seen[$fields[0]->requireObjectIdentifier()->encoding])) {
                throw new \UnexpectedValueException('PDF MAC authenticated attributes must be unambiguous.');
            }
            $oid = $fields[0]->encoding;
            $seen[$oid] = true;
            $values = $fields[1]->requireTag(49)->children();
            if (isset($expected[$oid]) && $fields[1]->encoding !== $expected[$oid]) {
                throw new \UnexpectedValueException('PDF MAC authenticated attribute value is inconsistent.');
            }
            if ($oid === $der->oid('1.2.840.113549.1.9.52')) {
                $protection = count($values) === 1 ? $values[0]->requireTag(48)->children() : [];
                if (count($protection) !== 2 || $protection[1]->tag !== 162
                    || (new PdfMacDigestAlgorithm($protection[0]))->method !== $selected
                    || $protection[1]->value !== $data[2]->value) {
                    throw new \UnexpectedValueException('PDF MAC algorithm protection has exactly its matching digest and MAC algorithms.');
                }
            }
        }
        if (!isset($seen[$der->oid('1.2.840.113549.1.9.3')], $seen[$der->oid('1.2.840.113549.1.9.4')])
            || !hash_equals(hash_hmac('sha256', $der->element(49, $data[5]->value), $macKey, true), $data[6]->requireTag(4)->value)) {
            throw new \UnexpectedValueException('PDF MAC CMS authentication failed.');
        }
    }

    /** @return array{maximumTokenBytes:int} */
    public function __debugInfo(): array
    {
        return ['maximumTokenBytes' => $this->maximumTokenBytes];
    }
}
