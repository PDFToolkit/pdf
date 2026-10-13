<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Standard handler V=5/R=5; supplement PDF pages 14–22, Algorithms 3.8–3.10. */
final readonly class Revision5EncryptionDictionary
{
    private DictionaryType $dictionary;

    public function __construct(
        public string $owner,
        public string $user,
        public string $ownerEncryption,
        public string $userEncryption,
        public string $permissionsBlock,
        public Revision5Permissions $permissions,
        public EncryptMetadata $encryptMetadata,
    ) {
        foreach ([[$owner, 48], [$user, 48], [$ownerEncryption, 32], [$userEncryption, 32], [$permissionsBlock, 16]] as [$bytes, $length]) {
            if (strlen($bytes) !== $length) {
                throw new \InvalidArgumentException('An R=5 encryption dictionary has an invalid entry length.');
            }
        }
        $this->dictionary = new DictionaryType(
            new Entry(new NameType('Filter'), new NameType('Standard')),
            new Entry(new NameType('V'), new IntegerType(5)),
            new Entry(new NameType('Length'), new IntegerType(256)),
            new Entry(new NameType('R'), new IntegerType(5)),
            new Entry(new NameType('O'), new HexadecimalString($owner)),
            new Entry(new NameType('U'), new HexadecimalString($user)),
            new Entry(new NameType('OE'), new HexadecimalString($ownerEncryption)),
            new Entry(new NameType('UE'), new HexadecimalString($userEncryption)),
            new Entry(new NameType('Perms'), new HexadecimalString($permissionsBlock)),
            new Entry(new NameType('P'), new IntegerType($permissions->value)),
            new Entry(new NameType('EncryptMetadata'), new BooleanType($encryptMetadata->value)),
            new Entry(new NameType('CF'), new DictionaryType(new Entry(new NameType('StdCF'), new DictionaryType(
                new Entry(new NameType('CFM'), new NameType('AESV3')),
                new Entry(new NameType('AuthEvent'), new NameType('DocOpen')),
                new Entry(new NameType('Length'), new IntegerType(32)),
            )))),
            new Entry(new NameType('StmF'), new NameType('StdCF')),
            new Entry(new NameType('StrF'), new NameType('StdCF')),
            new Entry(new NameType('EFF'), new NameType('StdCF')),
        );
    }

    public function dictionary(): DictionaryType
    {
        return $this->dictionary;
    }
}
