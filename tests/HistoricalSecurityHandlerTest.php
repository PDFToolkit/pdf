<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Security\FileIdentifier;
use PDF\Security\HistoricalDataCipher;
use PDF\Security\HistoricalEncryptionKey;
use PDF\Security\HistoricalKeyLength;
use PDF\Security\HistoricalObjectKeyDerivation;
use PDF\Security\HistoricalPermissions;
use PDF\Security\HistoricalSecurityRevision;
use PDF\Security\HistoricalStandardSecurityHandler;
use PDF\Security\InitializationVector;
use PDF\Security\OpenSslAes128Backend;
use PDF\Security\PasswordAccess;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\Rc4Cipher;
use PDF\Security\StandardCryptFilters;
use PDF\Security\StreamCryptFilter;
use PDF\Security\EncryptMetadata;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Filters\Crypt;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HistoricalSecurityHandlerTest extends TestCase
{
    public function testRc4MatchesRfc6229AtDistinctOffsets(): void
    {
        // https://www.rfc-editor.org/rfc/rfc6229, section 2, 40-bit key 1.
        $cipher = new Rc4Cipher();
        $key = new HistoricalEncryptionKey(hex2bin('0102030405'));
        $stream = $cipher->transform(str_repeat("\0", 4112), $key);
        self::assertSame('b2396305f03dc027ccc3524a0a1118a8', bin2hex(substr($stream, 0, 16)));
        self::assertSame('ff25b58995996707e51fbdf08b34d875', bin2hex(substr($stream, 4096, 16)));
        self::assertSame(str_repeat("\0", 4112), $cipher->transform($stream, $key));
    }

    public function testAes128MatchesTheNistCbcVector(): void
    {
        // NIST SP800-38A, F.2.1 CBC-AES128.Encrypt.
        $backend = new OpenSslAes128Backend();
        $key = new HistoricalEncryptionKey(hex2bin('2b7e151628aed2a6abf7158809cf4f3c'));
        $iv = new InitializationVector(hex2bin('000102030405060708090a0b0c0d0e0f'));
        $plain = hex2bin('6bc1bee22e409f96e93d7e117393172a');
        $cipher = $backend->encryptCbc($plain, $key, $iv, false);
        self::assertSame('7649abac8119b246cee98e9b12e9197d', bin2hex($cipher));
        self::assertSame($plain, $backend->decryptCbc($cipher, $key, $iv, false));
    }

    /** @return iterable<string, array{int}> */
    public static function qpdfVectors(): iterable
    {
        foreach ([0, 1, 2, 3] as $index) {
            yield 'independent qpdf vector ' . $index => [$index];
        }
    }

    #[DataProvider('qpdfVectors')]
    public function testCreatesAndAuthenticatesTheIndependentQpdfCredentials(int $index): void
    {
        $vectors = json_decode(file_get_contents(__DIR__ . '/Fixtures/adobe-encryption/qpdf-legacy-key-derivation.json'), true, 512, JSON_THROW_ON_ERROR);
        $vector = $vectors['vectors'][$index];
        $revision = HistoricalSecurityRevision::from($vector['R']);
        $handler = new HistoricalStandardSecurityHandler(aes: new OpenSslAes128Backend());
        $identifier = new FileIdentifier(new HexadecimalString(hex2bin($vector['ID'])));
        $operation = $handler->create(
            $revision,
            new PdfDocEncodedPassword(new HexadecimalString('user')),
            new PdfDocEncodedPassword(new HexadecimalString('owner')),
            new HistoricalPermissions(new IntegerType($vector['P']), $revision),
            $identifier,
            new HistoricalKeyLength(new IntegerType($vector['Length'])),
            $revision === HistoricalSecurityRevision::Revision4 ? HistoricalDataCipher::Aes128 : HistoricalDataCipher::Rc4,
            new EncryptMetadata($vector['EncryptMetadata'])
        );
        self::assertSame($vector['O'], bin2hex($operation->dictionary->owner->value->value));
        self::assertSame($vector['fileKey'], bin2hex($operation->key->bytes()));
        self::assertSame(PasswordAccess::User, $handler->authenticate(new PdfDocEncodedPassword(new HexadecimalString('user')), $operation->dictionary, $identifier)?->access);
        self::assertSame(PasswordAccess::Owner, $handler->authenticate(new PdfDocEncodedPassword(new HexadecimalString('owner')), $operation->dictionary, $identifier)?->access);
        self::assertNull($handler->authenticate(new PdfDocEncodedPassword(new HexadecimalString('incorrect')), $operation->dictionary, $identifier));
        self::assertNull($handler->authenticate(new PdfDocEncodedPassword(new HexadecimalString('user')), $operation->dictionary, new FileIdentifier(new HexadecimalString(str_repeat('Z', 16)))));
        $id = new ObjectIdentifier(new ObjectNumber(19), new GenerationNumber(7));
        $binary = implode('', array_map(chr(...), range(0, 255))) . "\nendstream\nendobj";
        $encrypted = $operation->encrypt($binary, $id);
        self::assertNotSame($binary, $encrypted);
        self::assertSame($binary, $operation->decrypt($encrypted, $id));
    }

    public function testObjectKeyUsesTheLowObjectAndGenerationBytesAndAesSalt(): void
    {
        $derivation = new HistoricalObjectKeyDerivation();
        $key = new HistoricalEncryptionKey('12345');
        $a = new ObjectIdentifier(new ObjectNumber(0x010203), new GenerationNumber(0x0405));
        $b = new ObjectIdentifier(new ObjectNumber(0x1010203), new GenerationNumber(0x0405));
        $rc4 = $derivation->derive($key, $a, HistoricalDataCipher::Rc4);
        self::assertSame(substr(md5('12345' . hex2bin('0302010504'), true), 0, 10), $rc4->bytes());
        self::assertSame($rc4->bytes(), $derivation->derive($key, $b, HistoricalDataCipher::Rc4)->bytes());
        self::assertNotSame($rc4->bytes(), $derivation->derive($key, $a, HistoricalDataCipher::Aes128)->bytes());
    }

    public function testOwnerPasswordDefaultsToTheUserAndEmptyUserAuthenticates(): void
    {
        $revision = HistoricalSecurityRevision::Revision2;
        $handler = new HistoricalStandardSecurityHandler();
        $identifier = new FileIdentifier(new HexadecimalString(str_repeat('A', 16)));
        $operation = $handler->create(
            $revision,
            new PdfDocEncodedPassword(new HexadecimalString('')),
            null,
            new HistoricalPermissions(new IntegerType(-64), $revision),
            $identifier,
            new HistoricalKeyLength(new IntegerType(40))
        );
        self::assertSame(PasswordAccess::Owner, $handler->authenticate(new PdfDocEncodedPassword(new HexadecimalString('')), $operation->dictionary, $identifier)?->access);
    }

    public function testExplicitIdentityIsAValidUnencryptedCryptFilter(): void
    {
        $stream = new StreamObject('uncompressed plaintext', null, new Crypt(), new FlateDecode());
        self::assertStringContainsString('/Crypt', $stream->toPdf(new SerializationContext()));
        self::assertSame('Identity', (new StreamCryptFilter())->explicit($stream)?->value);
    }

    public function testExplicitNamedCryptRequiresAFileSecurityOperation(): void
    {
        $this->expectException(\LogicException::class);
        (new StreamObject('secret', null, new Crypt(new NameType('StdCF'))))->toPdf(new SerializationContext());
    }

    public function testCryptFilterMustBeFirstInDecodingOrder(): void
    {
        $this->expectException(\LogicException::class);
        (new StreamObject('secret', null, new FlateDecode(), new Crypt()))->toPdf(new SerializationContext());
    }

    public function testEmbeddedFilesHaveTheirOwnExplicitDefaultSelector(): void
    {
        $filters = new StandardCryptFilters(new NameType('Identity'), new NameType('Identity'), new NameType('StdCF'));
        $regular = new StreamObject('text');
        $embedded = new StreamObject('attachment', new DictionaryType(new Entry(new NameType('Type'), new NameType('EmbeddedFile'))));
        self::assertSame('Identity', $filters->forStream($regular)->value);
        self::assertSame('StdCF', $filters->forStream($embedded)->value);
        self::assertSame('Identity', $filters->forStream(new StreamObject('attachment', $embedded->dictionary(), new Crypt()))->value);
    }

    /** @return iterable<string, array{HistoricalDataCipher}> */
    public static function explicitCiphers(): iterable
    {
        foreach (HistoricalDataCipher::cases() as $cipher) {
            yield $cipher->name => [$cipher];
        }
    }

    #[DataProvider('explicitCiphers')]
    public function testExplicitCryptUsesTheSharedKeyAsIsUnlikeImplicitStreams(HistoricalDataCipher $cipher): void
    {
        // PDF32000_2008.pdf p.46, 7.4.10, and p.66, Algorithm1(b).
        $operation = (new HistoricalStandardSecurityHandler(aes: new OpenSslAes128Backend()))->create(
            HistoricalSecurityRevision::Revision4,
            new PdfDocEncodedPassword(new HexadecimalString('user')),
            new PdfDocEncodedPassword(new HexadecimalString('owner')),
            new HistoricalPermissions(new IntegerType(-4), HistoricalSecurityRevision::Revision4),
            new FileIdentifier(new HexadecimalString(str_repeat('i', 16))),
            new HistoricalKeyLength(new IntegerType(128)),
            $cipher,
        );
        $plain = 'An explicit stream key';
        $identifier = new ObjectIdentifier(new ObjectNumber(42));
        $ciphertext = $operation->encryptStream($plain, $identifier, new StreamObject($plain, filters: new Crypt(new NameType('StdCF'))));
        $decoded = $cipher === HistoricalDataCipher::Rc4
            ? (new Rc4Cipher())->transform($ciphertext, $operation->key)
            : (new OpenSslAes128Backend())->decryptCbc(substr($ciphertext, 16), $operation->key, new InitializationVector(substr($ciphertext, 0, 16)), true);
        self::assertSame($plain, $decoded);
        $implicit = $operation->encryptStream($plain, $identifier, new StreamObject($plain));
        self::assertSame($plain, $operation->decrypt($implicit, $identifier));
        self::assertNotSame($implicit, $ciphertext);
    }

    public function testAttachmentOnlyEncryptionRequiresIdenticalPasswords(): void
    {
        $revision = HistoricalSecurityRevision::Revision4;
        $this->expectException(\InvalidArgumentException::class);
        (new HistoricalStandardSecurityHandler())->create(
            $revision,
            new PdfDocEncodedPassword(new HexadecimalString('user')),
            new PdfDocEncodedPassword(new HexadecimalString('owner')),
            new HistoricalPermissions(new IntegerType(-3904), $revision),
            new FileIdentifier(new HexadecimalString(str_repeat('A', 16))),
            new HistoricalKeyLength(new IntegerType(128)),
            filters: new StandardCryptFilters(new NameType('Identity'), new NameType('Identity'), new NameType('StdCF'))
        );
    }

    public function testReservedRevisionTwoPermissionBitsAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HistoricalPermissions(new IntegerType(-3904), HistoricalSecurityRevision::Revision2);
    }
}
