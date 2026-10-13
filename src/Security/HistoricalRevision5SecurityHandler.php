<?php

declare(strict_types=1);

namespace PDF\Security;

/**
 * Explicit historical R=5 protocol from adobe_supplement_iso32000.pdf, pages 19–21.
 * R=5 uses legacy unslowed SHA-256 password hashing. It is never a default handler.
 * This implements Algorithms 3.2a and 3.8–3.13, not modern R=6 or public-key security.
 */
final readonly class HistoricalRevision5SecurityHandler
{
    public function __construct(private Aes256Backend $backend, private RandomSource $random)
    {
    }

    public function create(PreparedPassword $user, PreparedPassword $owner, Revision5Permissions $permissions, EncryptMetadata $metadata): HistoricalRevision5Encryption
    {
        $key = new FileEncryptionKey($this->randomBytes(32));
        $userSalts = $this->randomBytes(16);
        $u = hash('sha256', $user->bytes() . substr($userSalts, 0, 8), true) . $userSalts;
        $ue = $this->backend->encryptCbc($key->bytes(), new FileEncryptionKey(hash('sha256', $user->bytes() . substr($userSalts, 8), true)), new InitializationVector(str_repeat("\0", 16)), false);
        $ownerSalts = $this->randomBytes(16);
        $o = hash('sha256', $owner->bytes() . substr($ownerSalts, 0, 8) . $u, true) . $ownerSalts;
        $oe = $this->backend->encryptCbc($key->bytes(), new FileEncryptionKey(hash('sha256', $owner->bytes() . substr($ownerSalts, 8) . $u, true)), new InitializationVector(str_repeat("\0", 16)), false);
        $block = $permissions->bytes() . ($metadata->value ? 'T' : 'F') . 'adb' . $this->randomBytes(4);
        $perms = $this->backend->encryptEcb($block, $key);
        return new HistoricalRevision5Encryption($key, new Revision5EncryptionDictionary($o, $u, $oe, $ue, $perms, $permissions, $metadata), $this->backend, $this->random);
    }

    public function authenticatesUser(PreparedPassword $password, Revision5EncryptionDictionary $dictionary): bool
    {
        return hash_equals(substr($dictionary->user, 0, 32), hash('sha256', $password->bytes() . substr($dictionary->user, 32, 8), true));
    }

    public function authenticatesOwner(PreparedPassword $password, Revision5EncryptionDictionary $dictionary): bool
    {
        return hash_equals(substr($dictionary->owner, 0, 32), hash('sha256', $password->bytes() . substr($dictionary->owner, 32, 8) . $dictionary->user, true));
    }

    /** Owner validation precedes user validation, as required by Algorithm 3.2a. */
    public function authenticate(PreparedPassword $password, Revision5EncryptionDictionary $dictionary): ?AuthenticatedFileKey
    {
        if ($this->authenticatesOwner($password, $dictionary)) {
            $intermediate = hash('sha256', $password->bytes() . substr($dictionary->owner, 40, 8) . $dictionary->user, true);
            $wrapped = $dictionary->ownerEncryption;
            $access = PasswordAccess::Owner;
        } elseif ($this->authenticatesUser($password, $dictionary)) {
            $intermediate = hash('sha256', $password->bytes() . substr($dictionary->user, 40, 8), true);
            $wrapped = $dictionary->userEncryption;
            $access = PasswordAccess::User;
        } else {
            return null;
        }
        $key = new FileEncryptionKey($this->backend->decryptCbc($wrapped, new FileEncryptionKey($intermediate), new InitializationVector(str_repeat("\0", 16)), false));
        $this->validatePermissions($key, $dictionary);
        return new AuthenticatedFileKey($key, $access);
    }

    /** Algorithm 3.13 rejects inconsistent P, EncryptMetadata, reserved bytes and magic. */
    public function validatePermissions(FileEncryptionKey $key, Revision5EncryptionDictionary $dictionary): void
    {
        $block = $this->backend->decryptEcb($dictionary->permissionsBlock, $key);
        $expected = $dictionary->permissions->bytes() . ($dictionary->encryptMetadata->value ? 'T' : 'F') . 'adb';
        if (!hash_equals($expected, substr($block, 0, 12))) {
            throw new \UnexpectedValueException('The encrypted permissions block does not match the encryption dictionary.');
        }
    }

    private function randomBytes(int $length): string
    {
        $bytes = $this->random->bytes($length);
        if (strlen($bytes) !== $length) {
            throw new \UnexpectedValueException('The random source returned an invalid byte count.');
        }
        return $bytes;
    }
}
