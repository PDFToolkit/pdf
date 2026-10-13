<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Security\EncryptMetadata;
use PDF\Security\DocumentMetadataExemption;
use PDF\Security\FileEncryptionKey;
use PDF\Security\HistoricalRevision5Encryption;
use PDF\Security\HistoricalRevision5SecurityHandler;
use PDF\Security\InitializationVector;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\PasswordAccess;
use PDF\Security\PreparedPassword;
use PDF\Security\RandomSource;
use PDF\Security\Revision5EncryptionDictionary;
use PDF\Security\Revision5Permissions;
use PDF\Security\Saslprep;
use PDF\Security\SystemRandomSource;
use PDF\Security\FileIdentifier;
use PDF\Security\HistoricalEncryptionKey;
use PDF\Security\HistoricalKeyLength;
use PDF\Security\HistoricalOwnerPasswordEntry;
use PDF\Security\HistoricalPublicKey256KeyDerivation;
use PDF\Security\HistoricalSecurityRevision;
use PDF\Security\HistoricalStandardKeyDerivation;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PublicKeyRecipient;
use PDF\Security\PublicKeySeed;
use PDF\Serialization\FileWriter;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\FileStructure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdobeEncryptionTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function saslprepMappings(): iterable
    {
        yield 'RFC 4013 soft hyphen' => ["I\u{00ad}X", 'IX'];
        yield 'RFC 4013 ordinal' => ["\u{00aa}", 'a'];
        yield 'RFC 4013 Roman numeral' => ["\u{2168}", 'IX'];
        yield 'non-ASCII space' => ["x\u{00a0}y", 'x y'];
        yield 'canonical ordering' => ["a\u{0315}\u{0300}", "\u{00e0}\u{0315}"];
        yield 'Hangul LVT composition' => ["\u{1100}\u{1161}\u{11a8}", "\u{ac01}"];
        yield 'Unicode 3.2 normalization correction' => ["\u{2f868}", "\u{2136a}"];
        yield 'BIDI numbers within RandAL text' => ["\u{0627}1\u{0628}", "\u{0627}1\u{0628}"];
        yield 'empty string' => ['', ''];
        yield 'case is preserved' => ['USER', 'USER'];
    }

    #[DataProvider('saslprepMappings')]
    public function testSaslprepUsesTheFrozenUnicode32Profile(string $input, string $expected): void
    {
        self::assertSame($expected, (new Saslprep())->normalize($input));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPasswords(): iterable
    {
        yield 'invalid UTF-8' => ["\xc0\xaf"];
        yield 'ASCII control' => ["\x07"];
        yield 'private use' => ["\u{e000}"];
        yield 'noncharacter' => ["\u{ffff}"];
        yield 'surrogate' => ["\xed\xa0\x80"];
        yield 'unassigned in 3.2 despite assignment in Unicode 4' => ["\u{1d2c}"];
        yield 'modern emoji is unassigned in 3.2' => ["\u{1f600}"];
        yield 'RandAL mixed with Latin' => ["\u{0627}x\u{0628}"];
        yield 'RandAL ending in a digit' => ["\u{0627}1"];
        yield 'RandAL beginning in a digit' => ["1\u{0627}"];
        yield 'prohibited after the truncation boundary' => [str_repeat('x', 128) . "\x07"];
    }

    #[DataProvider('invalidPasswords')]
    public function testSaslprepRejectsInvalidUnassignedProhibitedAndBidirectionalInput(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Saslprep())->prepare($input);
    }

    public function testTruncationIsExactly127BytesAfterFullPreparation(): void
    {
        $password = (new Saslprep())->prepare(str_repeat('x', 126) . "\u{00e9}");
        self::assertSame(str_repeat('x', 126) . "\xc3", $password->bytes());
        self::assertSame(127, strlen($password->bytes()));
        self::assertSame(str_repeat('a', 127), (new Saslprep())->prepare(str_repeat("\u{00aa}", 128))->bytes());
    }

    public function testNativeBackendMatchesNistSp80038ACbcAndEcbVectors(): void
    {
        $backend = new OpenSslAes256Backend();
        $key = new FileEncryptionKey($this->hex('603deb1015ca71be2b73aef0857d77811f352c073b6108d72d9810a30914dff4'));
        $iv = new InitializationVector($this->hex('000102030405060708090a0b0c0d0e0f'));
        $plain = $this->hex('6bc1bee22e409f96e93d7e117393172aae2d8a571e03ac9c9eb76fac45af8e51');
        $cbc = $this->hex('f58c4c04d6e5f1ba779eabfb5f7bfbd69cfc4e967edb808d679f777bc6702c7d');
        self::assertSame($cbc, $backend->encryptCbc($plain, $key, $iv, false));
        self::assertSame($plain, $backend->decryptCbc($cbc, $key, $iv, false));
        self::assertSame($this->hex('f3eed1bdb5d2a03c064b5a7e3db181f8'), $backend->encryptEcb(substr($plain, 0, 16), $key));
        self::assertSame(substr($plain, 0, 16), $backend->decryptEcb($this->hex('f3eed1bdb5d2a03c064b5a7e3db181f8'), $key));
    }

    public function testUserAndOwnerAuthenticationRecoverTheSameFileKey(): void
    {
        $handler = $this->handler();
        $encryption = $handler->create((new Saslprep())->prepare('user'), (new Saslprep())->prepare('owner'), new Revision5Permissions(-4), new EncryptMetadata(true));
        $user = $handler->authenticate(new PreparedPassword('user'), $encryption->dictionary);
        $owner = $handler->authenticate(new PreparedPassword('owner'), $encryption->dictionary);
        self::assertSame($encryption->key->bytes(), $user?->key->bytes());
        self::assertSame($encryption->key->bytes(), $owner?->key->bytes());
        self::assertSame(PasswordAccess::User, $user?->access);
        self::assertSame(PasswordAccess::Owner, $owner?->access);
        self::assertNull($handler->authenticate(new PreparedPassword('wrong'), $encryption->dictionary));
        self::assertFalse($handler->authenticatesUser(new PreparedPassword('owner'), $encryption->dictionary));
        self::assertFalse($handler->authenticatesOwner(new PreparedPassword('user'), $encryption->dictionary));
    }

    public function testOwnerTakesPrecedenceWhenPasswordsAreEqual(): void
    {
        $handler = $this->handler();
        $encryption = $handler->create(new PreparedPassword('same'), new PreparedPassword('same'), new Revision5Permissions(-4), new EncryptMetadata(false));
        self::assertSame(PasswordAccess::Owner, $handler->authenticate(new PreparedPassword('same'), $encryption->dictionary)?->access);
    }

    public function testCredentialsFromAnIndependentQpdfR5FileAuthenticate(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/adobe-encryption/qpdf-r5.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['vectors'] as $vector) {
            $dictionary = new Revision5EncryptionDictionary($this->hex($vector['O']), $this->hex($vector['U']), $this->hex($vector['OE']), $this->hex($vector['UE']), $this->hex($vector['Perms']), new Revision5Permissions($vector['P']), new EncryptMetadata($vector['EncryptMetadata']));
            foreach (['user' => PasswordAccess::User, 'owner' => PasswordAccess::Owner] as $password => $access) {
                $authenticated = $this->handler()->authenticate(new PreparedPassword($password), $dictionary);
                self::assertSame($this->hex($vector['fileKey']), $authenticated?->key->bytes());
                self::assertSame($access, $authenticated?->access);
            }
            self::assertNull($this->handler()->authenticate(new PreparedPassword('wrong'), $dictionary));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function tamperedCredentials(): iterable
    {
        yield 'encrypted permissions' => ['Perms'];
        yield 'clear permission flags' => ['P'];
        yield 'clear metadata flag' => ['EncryptMetadata'];
        yield 'wrapped user key' => ['UE'];
    }

    #[DataProvider('tamperedCredentials')]
    public function testPermissionValidationRejectsInconsistentCredentials(string $field): void
    {
        $handler = $this->handler();
        $encryption = $handler->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Revision5Permissions(-4), new EncryptMetadata(true));
        $dictionary = $encryption->dictionary;
        $tampered = new Revision5EncryptionDictionary(
            $dictionary->owner,
            $dictionary->user,
            $dictionary->ownerEncryption,
            $field === 'UE' ? str_repeat("\0", 32) : $dictionary->userEncryption,
            $field === 'Perms' ? str_repeat("\0", 16) : $dictionary->permissionsBlock,
            new Revision5Permissions($field === 'P' ? -3904 : -4),
            new EncryptMetadata($field !== 'EncryptMetadata')
        );
        $this->expectException(\UnexpectedValueException::class);
        $handler->authenticate(new PreparedPassword('user'), $tampered);
    }

    public function testEveryByteEmptyDataAndBlockBoundaryDataRoundTripWithIndependentIvs(): void
    {
        $encryption = $this->encryption();
        foreach (['', 'x', str_repeat('x', 16), implode('', array_map(chr(...), range(0, 255)))] as $plain) {
            $first = $encryption->encrypt($plain);
            $second = $encryption->encrypt($plain);
            self::assertNotSame(substr($first, 0, 16), substr($second, 0, 16));
            self::assertSame($plain, $encryption->decrypt($first));
            self::assertSame($plain, $encryption->decrypt($second));
            self::assertSame(0, (strlen($first) - 16) % 16);
        }
    }

    public function testAnInvalidPaddedCiphertextIsRejectedWithoutClaimingGeneralCbcAuthentication(): void
    {
        $encryption = $this->encryption();
        $ciphertext = $encryption->encrypt('');
        // Flip the IV so the final decrypted padding byte is 0 instead of 16.
        $ciphertext[15] = chr(ord($ciphertext[15]) ^ 16);
        $this->expectException(\UnexpectedValueException::class);
        $encryption->decrypt($ciphertext);
    }

    public function testContentStreamStringsAreEncryptedOnceWithTheStream(): void
    {
        $encryption = $this->encryption();
        $context = new SerializationContext(new ObjectNumber(), $encryption);
        $stream = new StreamObject(new ContentStreams(new Op\BeginTextObject(), new Op\ShowText(new LiteralString('visible after decryption')), new Op\EndTextObject()));
        $serialized = $context->serializeIndirect($stream);
        preg_match('/stream\n(.*)\nendstream/sD', $serialized, $matches);
        self::assertSame("BT\n(visible after decryption) Tj\nET\n", $encryption->decrypt($matches[1]));
        self::assertStringNotContainsString('visible after decryption', $serialized);
    }

    public function testObjectStreamMembersAreEncryptedOnceWithTheContainer(): void
    {
        $encryption = $this->encryption();
        $stream = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(22), new LiteralString('inside object stream')));
        $context = new SerializationContext(new ObjectNumber(), $encryption);
        $serialized = $context->serializeIndirect($stream);
        preg_match('/stream\n(.*)\nendstream/sD', $serialized, $matches);
        self::assertSame("22 0\n(inside object stream)\n", $encryption->decrypt($matches[1]));
    }

    public function testMetadataCanBeExplicitlyExcludedAndEncryptionDictionaryIsNeverEncrypted(): void
    {
        $encryption = $this->handler()->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Revision5Permissions(-4), new EncryptMetadata(false));
        $metadata = new StreamObject('<metadata/>', new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML'))));
        $context = new SerializationContext(new ObjectNumber(), $encryption, new DocumentMetadataExemption($metadata));
        self::assertStringContainsString("stream\n<metadata/>\nendstream", $context->serializeIndirect($metadata));
        self::assertStringContainsString((string) new HexadecimalString($encryption->dictionary->user), $context->serializeIndirect($encryption->dictionary->dictionary()));
        $pageMetadata = new StreamObject('<page-metadata/>', $metadata->dictionary());
        self::assertStringNotContainsString('<page-metadata/>', $context->serializeIndirect($pageMetadata));
    }

    public function testEncryptedRevisionSnapshotIsExplicitlyUnavailable(): void
    {
        $this->expectException(\LogicException::class);
        (new FileWriter(encryption: $this->encryption()))->revision($this->file());
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function signatureTypes(): iterable
    {
        yield 'signature' => ['Sig', true];
        yield 'timestamp' => ['DocTimeStamp', true];
        yield 'optional Type with three ByteRange pairs' => [null, true];
        yield 'explicit unrelated type' => ['Unrelated', false];
    }

    #[DataProvider('signatureTypes')]
    public function testOnlySignatureContentsAreExcludedAndOtherSignatureStringsAreEncrypted(?string $type, bool $excluded): void
    {
        $encryption = $this->encryption();
        $entries = [new Entry(new NameType('Filter'), new NameType('Adobe.PPKLite')),
            new Entry(new NameType('ByteRange'), new ArrayType(...array_map(static fn (int $part): IntegerType => new IntegerType($part), [0, 1, 2, 3, 4, 5]))),
            new Entry(new NameType('Contents'), new HexadecimalString('signature bytes')), new Entry(new NameType('Reason'), new LiteralString('private signature reason'))];
        if ($type !== null) {
            $entries[] = new Entry(new NameType('Type'), new NameType($type));
        }
        $context = new SerializationContext(new ObjectNumber(), $encryption);
        $serialized = $context->serializeIndirect(new DictionaryType(...$entries));
        self::assertSame($excluded, str_contains($serialized, (string) new HexadecimalString('signature bytes')));
        self::assertStringNotContainsString('private signature reason', $serialized);
        preg_match('/\/Reason <([0-9A-F]+)>/', $serialized, $match);
        self::assertSame('private signature reason', $encryption->decrypt($this->hex($match[1])));
    }

    public function testMetadataTypeCanBeAnIndirectName(): void
    {
        $encryption = $this->handler()->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Revision5Permissions(-4), new EncryptMetadata(false));
        $metadata = new StreamObject('<indirect-type-metadata/>', new DictionaryType(new Entry(new NameType('Type'), new IndirectReference(new NameType('Metadata'))), new Entry(new NameType('Subtype'), new NameType('XML'))));
        $context = new SerializationContext(new ObjectNumber(), $encryption, new DocumentMetadataExemption($metadata));
        self::assertStringContainsString("stream\n<indirect-type-metadata/>\nendstream", $context->serializeIndirect($metadata));
    }

    public function testSignatureWithoutByteRangeUsesNormalEncryptionAndPlainSerializationIsUnchanged(): void
    {
        $signature = new DictionaryType(
            new Entry(new NameType('Type'), new NameType('Sig')),
            new Entry(new NameType('Filter'), new NameType('Adobe.PPKLite')),
            new Entry(new NameType('Contents'), new LiteralString('object signature'))
        );
        self::assertStringContainsString('/Contents (object signature)', (new SerializationContext())->serialize($signature));
        $encryption = $this->encryption();
        $context = new SerializationContext(new ObjectNumber(), $encryption);
        $serialized = $context->serializeIndirect($signature);
        self::assertStringNotContainsString('object signature', $serialized);
        preg_match('/\/Contents <([0-9A-F]+)>/', $serialized, $match);
        self::assertSame('object signature', $encryption->decrypt($this->hex($match[1])));
    }

    public function testMetadataExemptionUsesTheOriginalIndirectStreamIdentityThroughSerializationWrappers(): void
    {
        $encryption = $this->handler()->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Revision5Permissions(-4), new EncryptMetadata(false));
        $metadata = new class ('<wrapped-metadata/>', new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML')))) extends StreamObject {
            public function toPdf(SerializationContext $context): string
            {
                return (new StreamObject($this->data, $this->dictionary(), ...$this->filters))->toPdf($context);
            }
        };
        $context = new SerializationContext(new ObjectNumber(), $encryption, new DocumentMetadataExemption($metadata));
        self::assertStringContainsString("stream\n<wrapped-metadata/>\nendstream", $context->serializeIndirect($metadata));
    }

    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function encryptedFormats(): iterable
    {
        yield 'traditional xref' => [CrossReferenceFormat::Table];
        yield 'xref stream' => [CrossReferenceFormat::Stream];
    }

    #[DataProvider('encryptedFormats')]
    public function testWriterActuallyProducesEncryptedBytesWithoutCreatingARevision(CrossReferenceFormat $format): void
    {
        $file = $this->declaredFile($format);
        $bytes = (new FileWriter(encryption: $this->encryption()))->serialize($file);
        self::assertStringStartsWith('%PDF-1.7', $bytes);
        self::assertStringContainsString('/Encrypt ', $bytes);
        self::assertStringContainsString('/R 5', $bytes);
        self::assertStringContainsString((string) $file->trailerEntries->get('ID'), $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
    }

    /** @return iterable<string, array{\PDF\Syntax\FileStructure\BasicType}> */
    public static function directTrailerStrings(): iterable
    {
        yield 'direct literal' => [new LiteralString('trailer secret')];
        yield 'nested array' => [new ArrayType(new IntegerType(1), new ArrayType(new HexadecimalString('trailer secret')))];
        yield 'nested dictionary' => [new DictionaryType(new Entry(new NameType('Private'), new ArrayType(new LiteralString('trailer secret'))))];
    }

    #[DataProvider('directTrailerStrings')]
    public function testEncryptedWriterRejectsUnencryptedDirectTrailerStrings(\PDF\Syntax\FileStructure\BasicType $value): void
    {
        $this->expectException(\LogicException::class);
        (new FileWriter(encryption: $this->encryption()))->serialize($this->declaredFile(CrossReferenceFormat::Table, new Entry(new NameType('PrivateNote'), $value)));
    }

    #[DataProvider('encryptedFormats')]
    public function testCustomTrailerStringsCanBeSuppliedAsEncryptedIndirectObjects(CrossReferenceFormat $format): void
    {
        $encryption = $this->encryption();
        $reference = new IndirectReference(new LiteralString('trailer secret'));
        $file = $this->declaredFile($format, new Entry(new NameType('PrivateNote'), $reference));
        $bytes = (new FileWriter(encryption: $encryption))->serialize($file);
        self::assertStringNotContainsString('trailer secret', $bytes);
        preg_match('/\d+ 0 obj\n<([0-9A-F]+)>\nendobj/', $bytes, $match);
        self::assertSame('trailer secret', $encryption->decrypt($this->hex($match[1])));
        self::assertMatchesRegularExpression('/\/PrivateNote \d+ 0 R/', $bytes);
    }

    public function testWriterRequiresExplicitAdobeExtensionAndFileIdentifiers(): void
    {
        $this->expectException(\LogicException::class);
        (new FileWriter(encryption: $this->encryption()))->serialize($this->file());
    }

    public function testRandomSourceCannotSilentlyReturnShortKeysOrSalts(): void
    {
        $short = new class () implements RandomSource {
            public function bytes(int $length): string
            {
                return str_repeat('x', $length - 1);
            }
        };
        $this->expectException(\UnexpectedValueException::class);
        (new HistoricalRevision5SecurityHandler(new OpenSslAes256Backend(), $short))->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Revision5Permissions(-4), new EncryptMetadata(true));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPermissions(): iterable
    {
        yield 'positive flags' => [4];
        yield 'reserved lower bit' => [-3];
        yield 'reserved high bits' => [-2147483648];
        yield 'outside signed integer' => [-2147483649];
    }

    #[DataProvider('invalidPermissions')]
    public function testReservedPermissionBitsAreValidated(int $flags): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Revision5Permissions($flags);
    }

    public function testLegacyStandardKeyDerivationMatchesIndependentQpdfR2R3AndR4Keys(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/adobe-encryption/qpdf-legacy-key-derivation.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['vectors'] as $vector) {
            $key = $this->legacyKey($vector);
            self::assertSame($this->hex($vector['fileKey']), $key->bytes());
            self::assertSame($vector['R'] === 2 ? 5 : 16, strlen($key->bytes()));
            $wrong = $vector;
            $wrong['userPasswordHex'] = bin2hex('wrong');
            self::assertNotSame($key->bytes(), $this->legacyKey($wrong)->bytes());
        }
    }

    public function testLegacyPasswordPaddingIsByteBasedAndTruncatesAt32Bytes(): void
    {
        $padding = $this->hex('28bf4e5e4e758a4164004e56fffa01082e2e00b6d0683e802f0ca9fe6453697a');
        self::assertSame($padding, (new PdfDocEncodedPassword(new HexadecimalString('')))->paddedBytes());
        self::assertSame("\xe4\xf6\xfc" . substr($padding, 0, 29), (new PdfDocEncodedPassword(new HexadecimalString("\xe4\xf6\xfc")))->paddedBytes());
        self::assertSame(str_repeat('x', 32), (new PdfDocEncodedPassword(new LiteralString(str_repeat('x', 100))))->paddedBytes());
    }

    public function testRevision2AlwaysDerivesFiveBytesAndMetadataIsOnlyAnR4Input(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/adobe-encryption/qpdf-legacy-key-derivation.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['vectors'] as $vector) {
            $changed = $vector;
            $changed['EncryptMetadata'] = !$vector['EncryptMetadata'];
            if ($vector['R'] === 2) {
                $changed['Length'] = 128;
            }
            if ($vector['R'] < 4) {
                self::assertSame($this->legacyKey($vector)->bytes(), $this->legacyKey($changed)->bytes());
            } else {
                self::assertNotSame($this->legacyKey($vector)->bytes(), $this->legacyKey($changed)->bytes());
            }
        }
    }

    public function testPublicKeySha256DerivationUsesOpaqueCmsBytesAndTheirExactOrder(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/adobe-encryption/public-key-sha256.json'), true, 512, JSON_THROW_ON_ERROR);
        $derived = [];
        foreach ($fixture['vectors'] as $vector) {
            $recipients = array_map(static fn (string $filename): PublicKeyRecipient => new PublicKeyRecipient(new HexadecimalString((string) file_get_contents(__DIR__ . '/Fixtures/adobe-encryption/' . $filename))), $vector['recipients']);
            $key = (new HistoricalPublicKey256KeyDerivation())->derive(new PublicKeySeed(new HexadecimalString($this->hex($vector['seed']))), new EncryptMetadata($vector['EncryptMetadata']), ...$recipients);
            self::assertSame($this->hex($vector['fileKey']), $key->bytes());
            self::assertSame(32, strlen($key->bytes()));
            $derived[] = $key->bytes();
        }
        self::assertCount(4, array_unique($derived));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidHistoricalKeyLengths(): iterable
    {
        yield 'too short' => [32];
        yield 'fractional byte' => [41];
        yield 'too long' => [136];
    }

    #[DataProvider('invalidHistoricalKeyLengths')]
    public function testHistoricalKeyLengthsAreValidated(int $bits): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HistoricalKeyLength(new IntegerType($bits));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidOwnerEntries(): iterable
    {
        yield 'empty' => [0];
        yield 'short' => [31];
        yield 'long' => [33];
    }

    #[DataProvider('invalidOwnerEntries')]
    public function testLegacyOwnerEntryLengthIsValidated(int $length): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HistoricalOwnerPasswordEntry(new HexadecimalString(str_repeat('x', $length)));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidFileIdentifiers(): iterable
    {
        yield 'short' => [15];
        yield 'long' => [17];
    }

    #[DataProvider('invalidFileIdentifiers')]
    public function testFileIdentifierLengthIsValidated(int $length): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileIdentifier(new HexadecimalString(str_repeat('x', $length)));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPublicKeySeeds(): iterable
    {
        yield 'short' => [19];
        yield 'long' => [21];
    }

    #[DataProvider('invalidPublicKeySeeds')]
    public function testPublicKeySeedLengthIsValidated(int $length): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PublicKeySeed(new HexadecimalString(str_repeat('x', $length)));
    }

    public function testPublicKeyDerivationRequiresAnOrderedRecipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HistoricalPublicKey256KeyDerivation())->derive(new PublicKeySeed(new HexadecimalString(str_repeat('x', 20))), new EncryptMetadata(true));
    }

    public function testOpaqueRecipientBytesCannotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PublicKeyRecipient(new HexadecimalString(''));
    }

    public function testLegacyPermissionIntegerMustFit32Bits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HistoricalStandardKeyDerivation())->derive(
            HistoricalSecurityRevision::Revision4,
            new PdfDocEncodedPassword(new LiteralString('user')),
            new HistoricalOwnerPasswordEntry(new HexadecimalString(str_repeat('x', 32))),
            new IntegerType(2147483648),
            new FileIdentifier(new HexadecimalString(str_repeat('x', 16))),
            new HistoricalKeyLength(new IntegerType(128)),
            new EncryptMetadata(true)
        );
    }

    /** @param array{R: int, userPasswordHex: string, O: string, P: int, ID: string, Length: int, EncryptMetadata: bool} $vector */
    private function legacyKey(array $vector): HistoricalEncryptionKey
    {
        return (new HistoricalStandardKeyDerivation())->derive(
            HistoricalSecurityRevision::from($vector['R']),
            new PdfDocEncodedPassword(new HexadecimalString($this->hex($vector['userPasswordHex']))),
            new HistoricalOwnerPasswordEntry(new HexadecimalString($this->hex($vector['O']))),
            new IntegerType($vector['P']),
            new FileIdentifier(new HexadecimalString($this->hex($vector['ID']))),
            new HistoricalKeyLength(new IntegerType($vector['Length'])),
            new EncryptMetadata($vector['EncryptMetadata'])
        );
    }

    private function handler(): HistoricalRevision5SecurityHandler
    {
        return new HistoricalRevision5SecurityHandler(new OpenSslAes256Backend(), new SystemRandomSource());
    }

    private function encryption(): HistoricalRevision5Encryption
    {
        return $this->handler()->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Revision5Permissions(-4), new EncryptMetadata(true));
    }

    private function hex(string $value): string
    {
        return hex2bin($value) ?: throw new \LogicException('Invalid fixed test vector.');
    }

    private function file(): FileStructure
    {
        $pages = new DictionaryType(new Entry(new NameType('Type'), new NameType('Pages')), new Entry(new NameType('Count'), new IntegerType(0)), new Entry(new NameType('Kids'), new ArrayType()));
        $catalog = new DictionaryType(new Entry(new NameType('Type'), new NameType('Catalog')), new Entry(new NameType('Pages'), new IndirectReference($pages)));
        return new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog), crossReferenceFormat: CrossReferenceFormat::Table);
    }

    private function declaredFile(CrossReferenceFormat $format = CrossReferenceFormat::Table, Entry ...$trailer): FileStructure
    {
        $source = $this->file();
        $catalog = $source->root->target()->with(new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(
            new Entry(new NameType('BaseVersion'), new NameType('1.7')),
            new Entry(new NameType('ExtensionLevel'), new IntegerType(3)),
        )))));
        $id = new ArrayType(new HexadecimalString(str_repeat('A', 16)), new HexadecimalString(str_repeat('B', 16)));
        return new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog), new DictionaryType(new Entry(new NameType('ID'), $id), ...$trailer), $format);
    }
}
