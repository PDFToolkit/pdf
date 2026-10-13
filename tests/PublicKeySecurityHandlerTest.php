<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Security as Sec;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Filters\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicKeySecurityHandlerTest extends TestCase
{
    private function certificate(string $name): Sec\X509Certificate
    {
        return new Sec\X509Certificate(new HexadecimalString(file_get_contents(__DIR__ . '/Fixtures/signing/' . $name . '.der')));
    }

    private function decrypter(string $name): Sec\OpenSslRecipientDecrypter
    {
        return new Sec\OpenSslRecipientDecrypter(new Sec\OpenSslIdentity(new Sec\CertificateChain($this->certificate($name)),
            file_get_contents(__DIR__ . '/Fixtures/signing/' . $name . '-private-key.pem')));
    }

    private function handler(Sec\PublicKeyPermissionByteOrder $order = Sec\PublicKeyPermissionByteOrder::IsoLeastSignificantFirst): Sec\PublicKeySecurityHandler
    {
        return new Sec\PublicKeySecurityHandler(new Sec\OpenSslCmsEnvelopeBackend(), aes128: new Sec\OpenSslAes128Backend(),
            aes256: new Sec\OpenSslAes256Backend(), permissionByteOrder: $order);
    }

    private function group(?int $permissions, string ...$names): Sec\PublicKeyRecipientGroup
    {
        return new Sec\PublicKeyRecipientGroup($permissions === null ? null : new Sec\PublicKeyPermissions(new IntegerType($permissions)),
            ...array_map($this->certificate(...), $names));
    }

    public function testLegacyEnvelopesGroupEqualPermissionsAndAuthenticateTheFirstMatchingList(): void
    {
        $operation = $this->handler()->createLegacy(Sec\PublicKeySubFilter::Pkcs7S4, new Sec\PublicKeyKeyLength(new IntegerType(128)),
            new Sec\PublicKeyPermissions(new IntegerType(-4)), $this->group(-4, 'signer'), $this->group(-4, 'recipient'),
            $this->group(2, 'signer'));
        self::assertCount(2, $operation->material->recipients);
        $first = $operation->material->authenticate($this->decrypter('signer'));
        self::assertSame(0, $first?->recipientIndex);
        self::assertSame(4294967292, $first?->permissions?->unsigned);
        self::assertFalse($first?->permissions?->ownerAccess());
        self::assertSame($operation->material->key->bytes(), $first?->key->bytes());
        self::assertSame(0, $operation->material->authenticate($this->decrypter('recipient'))?->recipientIndex);
        self::assertNull($operation->material->authenticate($this->decrypter('other-recipient')));
    }

    /** @return iterable<string, array{Sec\PublicKeyCipher, int}> */
    public static function ciphers(): iterable
    {
        yield 'RC4-40' => [Sec\PublicKeyCipher::Rc4, 40];
        yield 'RC4-128' => [Sec\PublicKeyCipher::Rc4, 128];
        yield 'AESV2' => [Sec\PublicKeyCipher::Aes128, 128];
        yield 'AESV3' => [Sec\PublicKeyCipher::Aes256, 256];
    }

    #[DataProvider('ciphers')]
    public function testIndependentNamedKeysSelectStringsStreamsAndIndividualEncryptedFiles(Sec\PublicKeyCipher $cipher, int $bits): void
    {
        $handler = $this->handler();
        $default = $handler->createFilter(new NameType('DefaultCryptFilter'), $cipher, new Sec\PublicKeyKeyLength(new IntegerType($bits)),
            new Sec\EncryptMetadata(true), Sec\PublicKeyRecipientScope::Document, new NameType('DocOpen'), $this->group(-4, 'signer'));
        $file = $handler->createFilter(new NameType('AttachmentKey'), $cipher, new Sec\PublicKeyKeyLength(new IntegerType($bits)),
            new Sec\EncryptMetadata(true), Sec\PublicKeyRecipientScope::IndividualStream, new NameType('EFOpen'), $this->group(null, 'recipient'));
        $operation = new Sec\PublicKeyEncryption(new Sec\PublicKeyFilterConfiguration(new NameType('DefaultCryptFilter'), new NameType('DefaultCryptFilter'),
            new NameType('AttachmentKey'), $default, $file), new Sec\PublicKeyPermissions(new IntegerType(-4)));
        $identifier = new ObjectIdentifier(new ObjectNumber(29));
        $data = implode('', array_map(chr(...), range(0, 255)));
        $string = $operation->encryptString($data, $identifier);
        self::assertSame($data, $default->operation->decrypt($string, $identifier));
        $stream = new StreamObject($data, new DictionaryType(new Entry(new NameType('Type'), new NameType('EmbeddedFile'))));
        $ciphertext = $operation->encryptStream($data, $identifier, $stream);
        self::assertSame($data, $file->operation->decryptDirect($ciphertext));
        self::assertNull($file->material->authenticate($this->decrypter('recipient'))?->permissions);
        self::assertNotSame($default->operation->key->bytes(), $file->operation->key->bytes());
        self::assertSame('AttachmentKey', $operation->streamFilters($stream)[0]->parameters()->get('Name')->value);
        self::assertSame($data, $operation->encryptStream($data, $identifier, new StreamObject($data, filters: new Crypt())));
        self::assertSame($bits, $default->dictionary()->get('Length')->value);
        self::assertSame($bits === 256 ? 5 : 4, $operation->version);
    }

    public function testPermissionByteOrderIsAnExplicitSourceInterpretation(): void
    {
        $permissions = new Sec\PublicKeyPermissions(new IntegerType(0x01020304));
        self::assertSame('04030201', bin2hex($permissions->bytes(Sec\PublicKeyPermissionByteOrder::IsoLeastSignificantFirst)));
        self::assertSame('01020304', bin2hex($permissions->bytes(Sec\PublicKeyPermissionByteOrder::MostSignificantFirst)));
    }
}
