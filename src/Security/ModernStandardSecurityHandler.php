<?php

declare(strict_types=1);

namespace PDF\Security;

/** ISO 32000-2 Algorithms 2.A, 2.B and 8-13; TS 32003 reuses R=6 password algorithms. */
final readonly class ModernStandardSecurityHandler
{
    public function __construct(private Aes256Backend $backend, private RandomSource $random, private Revision6PasswordHash $hash = new Revision6PasswordHash())
    {
    }

    public function create(PreparedPassword $user, PreparedPassword $owner, Pdf20Permissions $permissions, EncryptMetadata $metadata, ModernSecurityRevision $revision = ModernSecurityRevision::Aes256Cbc, ?string $kdfSalt = null): ModernFileEncryption
    {
        $key = new FileEncryptionKey($this->randomBytes(32));
        $userSalts = $this->randomBytes(16);
        $u = $this->hash->digest($user, substr($userSalts, 0, 8)) . $userSalts;
        $ue = $this->backend->encryptCbc($key->bytes(), new FileEncryptionKey($this->hash->digest($user, substr($userSalts, 8))), new InitializationVector(str_repeat("\0", 16)), false);
        $ownerSalts = $this->randomBytes(16);
        $o = $this->hash->digest($owner, substr($ownerSalts, 0, 8), $u) . $ownerSalts;
        $oe = $this->backend->encryptCbc($key->bytes(), new FileEncryptionKey($this->hash->digest($owner, substr($ownerSalts, 8), $u)), new InitializationVector(str_repeat("\0", 16)), false);
        $perms = $this->backend->encryptEcb($permissions->bytes() . ($metadata->value ? 'T' : 'F') . 'adb' . $this->randomBytes(4), $key);
        $dictionary = new ModernEncryptionDictionary($o, $u, $oe, $ue, $perms, $permissions, $metadata, $revision, $kdfSalt);
        return new ModernFileEncryption($key, $dictionary, $this->backend, $this->random);
    }

    public function authenticate(PreparedPassword $password, ModernEncryptionDictionary $dictionary): ?AuthenticatedFileKey
    {
        if (hash_equals(substr($dictionary->owner, 0, 32), $this->hash->digest($password, substr($dictionary->owner, 32, 8), $dictionary->user))) {
            $hash = $this->hash->digest($password, substr($dictionary->owner, 40, 8), $dictionary->user);
            $wrapped = $dictionary->ownerEncryption;
            $access = PasswordAccess::Owner;
        } elseif (hash_equals(substr($dictionary->user, 0, 32), $this->hash->digest($password, substr($dictionary->user, 32, 8)))) {
            $hash = $this->hash->digest($password, substr($dictionary->user, 40, 8));
            $wrapped = $dictionary->userEncryption;
            $access = PasswordAccess::User;
        } else {
            return null;
        }
        $key = new FileEncryptionKey($this->backend->decryptCbc($wrapped, new FileEncryptionKey($hash), new InitializationVector(str_repeat("\0", 16)), false));
        $this->validatePermissions($key, $dictionary);
        return new AuthenticatedFileKey($key, $access);
    }

    public function validatePermissions(FileEncryptionKey $key, ModernEncryptionDictionary $dictionary): void
    {
        $expected = $dictionary->permissions->bytes() . ($dictionary->encryptMetadata->value ? 'T' : 'F') . 'adb';
        if (!hash_equals($expected, substr($this->backend->decryptEcb($dictionary->permissionsBlock, $key), 0, 12))) {
            throw new \UnexpectedValueException('The authenticated permissions do not match the modern encryption dictionary.');
        }
    }

    private function randomBytes(int $length): string
    {
        $bytes = $this->random->bytes($length);
        return strlen($bytes) === $length ? $bytes : throw new \UnexpectedValueException('Invalid random byte count.');
    }
}
