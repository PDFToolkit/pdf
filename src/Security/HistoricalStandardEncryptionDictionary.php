<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Specification\Dictionary\AdditionalEncryptionDictionaryEntriesForTheStandardSecurityHandlerTable21;
use PDF\Specification\Dictionary\CryptFilterDictionaryTable25;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Standard R=2/3/4 security dictionary, ISO 7.6.3, PDF pages 64–76. */
final readonly class HistoricalStandardEncryptionDictionary
{
    private DictionaryType $dictionary;

    public function __construct(
        public HistoricalSecurityRevision $revision,
        public HistoricalDataCipher $cipher,
        public HistoricalKeyLength $length,
        public HistoricalOwnerPasswordEntry $owner,
        public HexadecimalString $user,
        public HistoricalPermissions $permissions,
        public EncryptMetadata $encryptMetadata = new EncryptMetadata(true),
        public StandardCryptFilters $filters = new StandardCryptFilters(),
    ) {
        if (strlen($user->value) !== 32 || $permissions->revision !== $revision) {
            throw new \InvalidArgumentException('Historical credentials must be 32 bytes and use the same permission revision.');
        }
        if ($revision === HistoricalSecurityRevision::Revision2 && $length->bytes() !== 5) {
            throw new \InvalidArgumentException('R=2 requires a 40-bit encryption key.');
        }
        if ($cipher === HistoricalDataCipher::Aes128 && ($revision !== HistoricalSecurityRevision::Revision4 || $length->bytes() !== 16)) {
            throw new \InvalidArgumentException('AESV2 requires R=4 and a 128-bit file key.');
        }
        if ($revision !== HistoricalSecurityRevision::Revision4 && (!$encryptMetadata->value
            || $filters->strings->value !== 'StdCF' || $filters->streams->value !== 'StdCF' || $filters->embeddedFiles->value !== 'StdCF')) {
            throw new \InvalidArgumentException('Metadata exemptions and crypt filter selection require R=4.');
        }
        $version = match ($revision) {
            HistoricalSecurityRevision::Revision2 => 1,
            HistoricalSecurityRevision::Revision3 => 2,
            HistoricalSecurityRevision::Revision4 => 4,
        };
        $entries = [
            new Entry(new NameType('Filter'), new NameType('Standard')),
            new Entry(new NameType('V'), new IntegerType($version)),
            new Entry(new NameType('R'), new IntegerType($revision->value)),
            new Entry(new NameType('O'), $owner->value),
            new Entry(new NameType('U'), $user),
            new Entry(new NameType('P'), $permissions->value),
        ];
        if ($revision !== HistoricalSecurityRevision::Revision2) {
            $entries[] = new Entry(new NameType('Length'), $length->bits);
        }
        if ($revision === HistoricalSecurityRevision::Revision4) {
            $entries[] = new Entry(new NameType('EncryptMetadata'), new BooleanType($encryptMetadata->value));
            $entries[] = new Entry(new NameType('CF'), new DictionaryType(new Entry(new NameType('StdCF'), new CryptFilterDictionaryTable25(
                new Entry(new NameType('CFM'), new NameType($cipher->value)),
                new Entry(new NameType('Length'), new IntegerType($length->bytes())),
                new Entry(new NameType('AuthEvent'), new NameType('DocOpen')),
            ))));
            $entries[] = new Entry(new NameType('StrF'), $filters->strings);
            $entries[] = new Entry(new NameType('StmF'), $filters->streams);
            if ($filters->embeddedFiles->value !== $filters->streams->value) {
                $entries[] = new Entry(new NameType('EFF'), $filters->embeddedFiles);
            }
        }
        $this->dictionary = new AdditionalEncryptionDictionaryEntriesForTheStandardSecurityHandlerTable21(...$entries);
        $this->dictionary->validate();
    }

    public function dictionary(): DictionaryType
    {
        return $this->dictionary;
    }
}
