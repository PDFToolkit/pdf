<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\StringObject\HexadecimalString;

/** ISO 7.6.3 Algorithms 2–7, physical PDF pages 69–72; explicit legacy protocols. */
final readonly class HistoricalStandardSecurityHandler
{
    public function __construct(
        private Rc4Cipher $rc4 = new Rc4Cipher(),
        private HistoricalStandardKeyDerivation $derivation = new HistoricalStandardKeyDerivation(),
        private RandomSource $random = new SystemRandomSource(),
        private ?Aes128Backend $aes = null,
    ) {
    }

    public function create(
        HistoricalSecurityRevision $revision,
        PdfDocEncodedPassword $user,
        ?PdfDocEncodedPassword $owner,
        HistoricalPermissions $permissions,
        FileIdentifier $identifier,
        HistoricalKeyLength $length,
        HistoricalDataCipher $cipher = HistoricalDataCipher::Rc4,
        EncryptMetadata $metadata = new EncryptMetadata(true),
        StandardCryptFilters $filters = new StandardCryptFilters(),
    ): HistoricalStandardEncryption {
        $ownerPassword = $owner === null || $owner->isEmpty() ? $user : $owner;
        if ($filters->strings->value === 'Identity' && $filters->streams->value === 'Identity' && $filters->embeddedFiles->value !== 'Identity'
            && !hash_equals($user->paddedBytes(), $ownerPassword->paddedBytes())) {
            throw new \InvalidArgumentException('Attachment-only encryption requires the same user and owner password.');
        }
        $ownerKey = $this->ownerKey($ownerPassword, $revision, $length);
        $o = $this->rc4->transform($user->paddedBytes(), $ownerKey);
        if ($revision !== HistoricalSecurityRevision::Revision2) {
            for ($iteration = 1; $iteration <= 19; ++$iteration) {
                $o = $this->rc4->transform($o, $this->iterationKey($ownerKey, $iteration));
            }
        }
        $ownerEntry = new HistoricalOwnerPasswordEntry(new HexadecimalString($o));
        $key = $this->derivation->derive($revision, $user, $ownerEntry, $permissions->value, $identifier, $length, $metadata);
        $dictionary = new HistoricalStandardEncryptionDictionary(
            $revision,
            $cipher,
            $length,
            $ownerEntry,
            $this->userValue($key, $identifier, $revision),
            $permissions,
            $metadata,
            $filters
        );
        return new HistoricalStandardEncryption($key, $dictionary, $identifier, $this->rc4, $this->random, $this->aes);
    }

    /** Algorithms 4–5: only the first 16 bytes are significant for R>=3. */
    public function userValue(HistoricalEncryptionKey $key, FileIdentifier $identifier, HistoricalSecurityRevision $revision): HexadecimalString
    {
        $padding = (new PdfDocEncodedPassword(new HexadecimalString('')))->paddedBytes();
        $value = $revision === HistoricalSecurityRevision::Revision2 ? $padding : hash('md5', $padding . $identifier->value->value, true);
        $value = $this->rc4->transform($value, $key);
        if ($revision !== HistoricalSecurityRevision::Revision2) {
            for ($iteration = 1; $iteration <= 19; ++$iteration) {
                $value = $this->rc4->transform($value, $this->iterationKey($key, $iteration));
            }
            $value .= str_repeat("\0", 16);
        }
        return new HexadecimalString($value);
    }

    public function authenticate(PdfDocEncodedPassword $password, HistoricalStandardEncryptionDictionary $dictionary, FileIdentifier $identifier): ?AuthenticatedHistoricalFileKey
    {
        $ownerKey = $this->ownerKey($password, $dictionary->revision, $dictionary->length);
        $candidate = $dictionary->owner->value->value;
        if ($dictionary->revision === HistoricalSecurityRevision::Revision2) {
            $candidate = $this->rc4->transform($candidate, $ownerKey);
        } else {
            for ($iteration = 19; $iteration >= 0; --$iteration) {
                $candidate = $this->rc4->transform($candidate, $this->iterationKey($ownerKey, $iteration));
            }
        }
        $ownerFileKey = $this->userKey(new PdfDocEncodedPassword(new HexadecimalString($candidate)), $dictionary, $identifier);
        if ($ownerFileKey !== null) {
            return new AuthenticatedHistoricalFileKey($ownerFileKey, PasswordAccess::Owner);
        }
        $userFileKey = $this->userKey($password, $dictionary, $identifier);
        return $userFileKey === null ? null : new AuthenticatedHistoricalFileKey($userFileKey, PasswordAccess::User);
    }

    public function validateKey(HistoricalEncryptionKey $key, HistoricalStandardEncryptionDictionary $dictionary, FileIdentifier $identifier): void
    {
        $count = $dictionary->revision === HistoricalSecurityRevision::Revision2 ? 32 : 16;
        if (!hash_equals(substr($dictionary->user->value, 0, $count), substr($this->userValue($key, $identifier, $dictionary->revision)->value, 0, $count))) {
            throw new \UnexpectedValueException('The historical file key does not match the U entry and file identifier.');
        }
    }

    private function userKey(PdfDocEncodedPassword $password, HistoricalStandardEncryptionDictionary $dictionary, FileIdentifier $identifier): ?HistoricalEncryptionKey
    {
        $key = $this->derivation->derive(
            $dictionary->revision,
            $password,
            $dictionary->owner,
            $dictionary->permissions->value,
            $identifier,
            $dictionary->length,
            $dictionary->encryptMetadata
        );
        $count = $dictionary->revision === HistoricalSecurityRevision::Revision2 ? 32 : 16;
        return hash_equals(substr($dictionary->user->value, 0, $count), substr($this->userValue($key, $identifier, $dictionary->revision)->value, 0, $count)) ? $key : null;
    }

    private function ownerKey(PdfDocEncodedPassword $password, HistoricalSecurityRevision $revision, HistoricalKeyLength $length): HistoricalEncryptionKey
    {
        $digest = hash('md5', $password->paddedBytes(), true);
        if ($revision !== HistoricalSecurityRevision::Revision2) {
            for ($iteration = 0; $iteration < 50; ++$iteration) {
                $digest = hash('md5', $digest, true);
            }
        }
        return new HistoricalEncryptionKey(substr($digest, 0, $revision === HistoricalSecurityRevision::Revision2 ? 5 : $length->bytes()));
    }

    private function iterationKey(HistoricalEncryptionKey $key, int $iteration): HistoricalEncryptionKey
    {
        return new HistoricalEncryptionKey($key->bytes() ^ str_repeat(chr($iteration), strlen($key->bytes())));
    }
}
