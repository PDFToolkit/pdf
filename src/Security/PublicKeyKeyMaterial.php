<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\IntegerType;

/** Immutable ordered CMS envelopes and seed-bound file/filter key. */
final readonly class PublicKeyKeyMaterial
{
    /** @var non-empty-list<PublicKeyRecipient> */
    public array $recipients;
    public HistoricalEncryptionKey|FileEncryptionKey $key;

    public function __construct(
        private PublicKeySeed $seed,
        public PublicKeyKeyLength $length,
        public EncryptMetadata $metadata,
        public PublicKeyRecipientScope $scope,
        public PublicKeyPermissionByteOrder $permissionByteOrder,
        PublicKeyRecipient ...$recipients
    ) {
        if ($recipients === [] || ($scope === PublicKeyRecipientScope::IndividualStream && count($recipients) !== 1)) {
            throw new \InvalidArgumentException('Document recipients are nonempty; individual-stream recipients contain exactly one envelope.');
        }
        $this->recipients = array_values($recipients);
        $this->key = (new PublicKeyKeyDerivation())->derive($seed, $length, $metadata, ...$recipients);
    }

    public function recipientObject(): ArrayType|StringType
    {
        return $this->scope === PublicKeyRecipientScope::IndividualStream ? $this->recipients[0]->value
            : new ArrayType(...array_map(static fn (PublicKeyRecipient $recipient) => $recipient->value, $this->recipients));
    }

    public function authenticate(RecipientDecrypter $decrypter): ?AuthenticatedPublicKey
    {
        foreach ($this->recipients as $index => $recipient) {
            $payload = $decrypter->decrypt($recipient);
            if ($payload === null) {
                continue;
            }
            $expectedLength = $this->scope === PublicKeyRecipientScope::Document ? 24 : 20;
            if (strlen($payload) !== $expectedLength || !hash_equals($this->seed->bytes(), substr($payload, 0, 20))) {
                throw new \UnexpectedValueException('The decrypted recipient payload must contain the bound 20-byte seed and scope-specific permissions.');
            }
            $permissions = null;
            if ($expectedLength === 24) {
                $word = unpack($this->permissionByteOrder->value . 'permissions', substr($payload, 20));
                if ($word === false || !isset($word['permissions']) || !is_int($word['permissions'])) {
                    throw new \UnexpectedValueException('Invalid public-key permission word.');
                }
                $permissions = new PublicKeyPermissions(new IntegerType($word['permissions']));
            }
            return new AuthenticatedPublicKey($this->key, $permissions, $index);
        }
        return null;
    }
}
