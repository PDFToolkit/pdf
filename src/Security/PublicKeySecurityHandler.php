<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\NameType;

/** Actual CMS recipient grouping and seed/key construction (ISO7.6.4.3 and Adobe AESV3). */
final readonly class PublicKeySecurityHandler
{
    public function __construct(
        private CmsEnvelopeBackend $cms,
        private PublicKeyPermissionByteOrder $permissionByteOrder,
        private RandomSource $random = new SystemRandomSource(),
        private ?Aes128Backend $aes128 = null,
        private ?Aes256Backend $aes256 = null
    ) {
    }

    public function createLegacy(
        PublicKeySubFilter $subFilter,
        PublicKeyKeyLength $length,
        PublicKeyPermissions $documentPermissions,
        PublicKeyRecipientGroup ...$groups
    ): LegacyPublicKeyEncryption {
        if ($subFilter === PublicKeySubFilter::Pkcs7S5) {
            throw new \InvalidArgumentException('adbe.pkcs7.s5 is constructed from explicit crypt filters.');
        }
        $material = $this->material($length, new EncryptMetadata(true), PublicKeyRecipientScope::Document, ...$groups);
        return new LegacyPublicKeyEncryption($subFilter, $material, $documentPermissions, $this->operation(PublicKeyCipher::Rc4, $material));
    }

    public function createFilter(
        NameType $name,
        PublicKeyCipher $cipher,
        PublicKeyKeyLength $length,
        EncryptMetadata $metadata,
        PublicKeyRecipientScope $scope,
        NameType $authorizationEvent,
        PublicKeyRecipientGroup ...$groups
    ): PublicKeyCryptFilter {
        if (($cipher === PublicKeyCipher::Aes128 && $length->bytes() !== 16)
            || ($cipher === PublicKeyCipher::Aes256 && $length->bytes() !== 32)
            || ($cipher === PublicKeyCipher::Rc4 && $length->bytes() > 16)
            || ($scope === PublicKeyRecipientScope::IndividualStream && !$metadata->value)) {
            throw new \InvalidArgumentException('The selected cipher key length or metadata/recipient scope is invalid.');
        }
        $material = $this->material($length, $metadata, $scope, ...$groups);
        return new PublicKeyCryptFilter($name, $material, $this->operation($cipher, $material), $authorizationEvent);
    }

    private function operation(PublicKeyCipher $cipher, PublicKeyKeyMaterial $material): PublicKeyCipherOperation
    {
        return new PublicKeyCipherOperation($cipher, $material->key, $this->aes128, $this->aes256, $this->random);
    }

    private function material(
        PublicKeyKeyLength $length,
        EncryptMetadata $metadata,
        PublicKeyRecipientScope $scope,
        PublicKeyRecipientGroup ...$groups
    ): PublicKeyKeyMaterial {
        if ($groups === []) {
            throw new \InvalidArgumentException('Public-key security requires an explicit recipient group.');
        }
        $grouped = [];
        $permissions = [];
        foreach ($groups as $group) {
            if ($scope === PublicKeyRecipientScope::Document ? $group->permissions === null : $group->permissions !== null) {
                throw new \InvalidArgumentException('Document recipients require permissions; individual-stream recipients omit them.');
            }
            $key = $group->permissions === null ? 'stream' : (string) $group->permissions->unsigned;
            $grouped[$key] ??= [];
            array_push($grouped[$key], ...$group->certificates);
            $permissions[$key] = $group->permissions;
        }
        $seed = new PublicKeySeed(new \PDF\Syntax\Object\StringObject\HexadecimalString($this->random->bytes(20)));
        $recipients = [];
        foreach ($grouped as $key => $certificates) {
            $payload = $seed->bytes() . ($permissions[$key]?->bytes($this->permissionByteOrder) ?? '');
            $recipient = $this->cms->envelope($payload, ...$certificates);
            if ($recipient->permissions !== null && $recipient->permissions->unsigned !== $permissions[$key]?->unsigned) {
                throw new \UnexpectedValueException('The CMS backend declared permissions different from its supplied payload.');
            }
            $recipients[] = new PublicKeyRecipient($recipient->value, $permissions[$key]);
        }
        return new PublicKeyKeyMaterial($seed, $length, $metadata, $scope, $this->permissionByteOrder, ...$recipients);
    }
}
