<?php

declare(strict_types=1);

namespace PDF\Security;

/** Ordered seed/envelopes digest, ISO 7.6.4.3 PDF p.75 and Adobe AESV3 PDF p.21. */
final readonly class PublicKeyKeyDerivation
{
    public function derive(PublicKeySeed $seed, PublicKeyKeyLength $length, EncryptMetadata $metadata, PublicKeyRecipient ...$recipients): HistoricalEncryptionKey|FileEncryptionKey
    {
        if ($recipients === []) {
            throw new \InvalidArgumentException('Public-key key derivation requires ordered CMS envelopes.');
        }
        $hash = hash_init($length->bytes() === 32 ? 'sha256' : 'sha1');
        hash_update($hash, $seed->bytes());
        foreach ($recipients as $recipient) {
            hash_update($hash, $recipient->value->value);
        }
        if (!$metadata->value) {
            hash_update($hash, "\xff\xff\xff\xff");
        }
        $bytes = substr(hash_final($hash, true), 0, $length->bytes());
        return $length->bytes() === 32 ? new FileEncryptionKey($bytes) : new HistoricalEncryptionKey($bytes);
    }
}
