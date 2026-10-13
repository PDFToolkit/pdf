<?php

declare(strict_types=1);

namespace PDF\Security;

/**
 * SHA-256 public-key key primitive, adobe_supplement_iso32000.pdf PDF page 21,
 * continuing the ordered-input algorithm from ISO 32000-1, 7.6.4.3.
 * Seed and recipient CMS bytes are explicit inputs. This is not a CMS processor,
 * password security handler, public-key writer or certificate validator.
 */
final readonly class HistoricalPublicKey256KeyDerivation
{
    public function derive(PublicKeySeed $seed, EncryptMetadata $metadata, PublicKeyRecipient ...$recipients): FileEncryptionKey
    {
        if ($recipients === []) {
            throw new \InvalidArgumentException('Public-key derivation requires at least one ordered recipient byte string.');
        }
        $digest = hash_init('sha256');
        hash_update($digest, $seed->bytes());
        foreach ($recipients as $recipient) {
            hash_update($digest, $recipient->value->value);
        }
        if (!$metadata->value) {
            hash_update($digest, "\xff\xff\xff\xff");
        }
        return new FileEncryptionKey(hash_final($digest, true));
    }
}
