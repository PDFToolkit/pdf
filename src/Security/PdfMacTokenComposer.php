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
    private PdfMacTokenVerifier $verifier;

    public function __construct(FileEncryptionKey $fileKey, string $kdfSalt, RandomSource $random, public SignatureDigestMethod $digest = SignatureDigestMethod::SHA256)
    {
        if (strlen($kdfSalt) !== 32 || !in_array($digest, [SignatureDigestMethod::SHA256, SignatureDigestMethod::SHA384, SignatureDigestMethod::SHA512, SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512], true)) {
            throw new \InvalidArgumentException('PDF MAC requires 32-byte KDFSalt and a TS 32004 Table 8 digest.');
        }
        $this->verifier = new PdfMacTokenVerifier($fileKey, $kdfSalt);
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
        $this->verifier->verify($token, $coveredBytes, $signatureValue);
    }

    /** @return array{digest:string} */
    public function __debugInfo(): array
    {
        return ['digest' => $this->digest->value];
    }
}
