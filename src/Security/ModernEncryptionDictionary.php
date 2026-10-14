<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Modern standard security handler, ISO 32000-2 7.6.4.4 and TS 32003 Table 3. */
final readonly class ModernEncryptionDictionary
{
    private DictionaryType $value;

    public function __construct(
        public string $owner,
        public string $user,
        public string $ownerEncryption,
        public string $userEncryption,
        public string $permissionsBlock,
        public Pdf20Permissions $permissions,
        public EncryptMetadata $encryptMetadata,
        public ModernSecurityRevision $revision = ModernSecurityRevision::Aes256Cbc,
        public ?string $kdfSalt = null,
    ) {
        foreach ([[$owner, 48], [$user, 48], [$ownerEncryption, 32], [$userEncryption, 32], [$permissionsBlock, 16]] as [$bytes, $length]) {
            if (strlen($bytes) !== $length) {
                throw new \InvalidArgumentException('A modern encryption dictionary has an invalid credential length.');
            }
        }
        if (($kdfSalt !== null && strlen($kdfSalt) !== 32) || ($permissions->requireMac && $kdfSalt === null)) {
            throw new \InvalidArgumentException('PDF MAC requires a direct 32-byte KDFSalt.');
        }
        $this->value = new DictionaryType(
            new Entry(new NameType('Filter'), new NameType('Standard')),
            new Entry(new NameType('V'), new IntegerType($revision === ModernSecurityRevision::Aes256Gcm ? 6 : 5)),
            new Entry(new NameType('R'), new IntegerType($revision->value)),
            new Entry(new NameType('Length'), new IntegerType(256)),
            new Entry(new NameType('O'), new HexadecimalString($owner)),
            new Entry(new NameType('U'), new HexadecimalString($user)),
            new Entry(new NameType('OE'), new HexadecimalString($ownerEncryption)),
            new Entry(new NameType('UE'), new HexadecimalString($userEncryption)),
            new Entry(new NameType('Perms'), new HexadecimalString($permissionsBlock)),
            new Entry(new NameType('P'), new IntegerType($permissions->value)),
            new Entry(new NameType('EncryptMetadata'), new BooleanType($encryptMetadata->value)),
            new Entry(new NameType('CF'), new DictionaryType(new Entry(new NameType('StdCF'), new DictionaryType(
                new Entry(new NameType('CFM'), new NameType($revision === ModernSecurityRevision::Aes256Gcm ? 'AESV4' : 'AESV3')),
                new Entry(new NameType('AuthEvent'), new NameType('DocOpen')),
                new Entry(new NameType('Length'), new IntegerType(32)),
            )))),
            new Entry(new NameType('StmF'), new NameType('StdCF')),
            new Entry(new NameType('StrF'), new NameType('StdCF')),
            new Entry(new NameType('EFF'), new NameType('StdCF')),
            ...($kdfSalt === null ? [] : [new Entry(new NameType('KDFSalt'), new HexadecimalString($kdfSalt))]),
        );
    }

    public function dictionary(): DictionaryType
    {
        return $this->value;
    }
}
