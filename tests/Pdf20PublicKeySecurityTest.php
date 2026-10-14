<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Security as Sec;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Pdf20PublicKeySecurityTest extends TestCase
{
    private function permissions(int $word = -4): Sec\PublicKeyPermissions
    {
        return new Sec\PublicKeyPermissions(new IntegerType($word));
    }

    private function material(int $bits = 256, Sec\PublicKeyPermissionByteOrder $order = Sec\PublicKeyPermissionByteOrder::MostSignificantFirst, ?Sec\PublicKeyPermissions $permissions = null, Sec\PublicKeyRecipientScope $scope = Sec\PublicKeyRecipientScope::Document): Sec\PublicKeyKeyMaterial
    {
        return new Sec\PublicKeyKeyMaterial(new Sec\PublicKeySeed(new HexadecimalString(str_repeat('s', 20))), new Sec\PublicKeyKeyLength(new IntegerType($bits)), new Sec\EncryptMetadata(true), $scope, $order, new Sec\PublicKeyRecipient(new HexadecimalString('opaque CMS bytes'), $permissions));
    }

    private function cryptOperation(Sec\PublicKeyCipher $cipher, Sec\PublicKeyKeyMaterial $material): Sec\PublicKeyEncryption
    {
        $name = new NameType($material->scope === Sec\PublicKeyRecipientScope::Document ? 'DefaultCryptFilter' : 'DefEmbeddedFile');
        $filter = new Sec\PublicKeyCryptFilter($name, $material, new Sec\PublicKeyCipherOperation($cipher, $material->key, aes128: new Sec\OpenSslAes128Backend(), aes256: new Sec\OpenSslAes256Backend()), new NameType($material->scope === Sec\PublicKeyRecipientScope::Document ? 'DocOpen' : 'EFOpen'));
        $identity = new NameType('Identity');
        return new Sec\PublicKeyEncryption(new Sec\PublicKeyFilterConfiguration($material->scope === Sec\PublicKeyRecipientScope::Document ? $name : $identity, $material->scope === Sec\PublicKeyRecipientScope::Document ? $name : $identity, $name, $filter), $this->permissions(0));
    }

    private function legacyOperation(Sec\PublicKeySubFilter $subfilter, Sec\PublicKeyKeyMaterial $material): Sec\LegacyPublicKeyEncryption
    {
        return new Sec\LegacyPublicKeyEncryption($subfilter, $material, $this->permissions(0), new Sec\PublicKeyCipherOperation(Sec\PublicKeyCipher::Rc4, $material->key));
    }

    private function validate(Sec\FileEncryption $operation, string $header = '2.0', ?string $catalogVersion = null, bool $adobe = false): void
    {
        $entries = [];
        if ($catalogVersion !== null) {
            $entries[] = new Entry(new NameType('Version'), new NameType($catalogVersion));
        }
        if ($adobe) {
            $entries[] = new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(new Entry(new NameType('BaseVersion'), new NameType('1.7')), new Entry(new NameType('ExtensionLevel'), new IntegerType(3))))));
        }
        $operation->validateFile(new FileHeader($header), new DictionaryType(...$entries), new Sec\FileIdentifier(new HexadecimalString(str_repeat('i', 16))));
    }

    /** @return iterable<string, array{Sec\PublicKeyCipher, int}> */
    public static function cryptCiphers(): iterable
    {
        yield 'RC4' => [Sec\PublicKeyCipher::Rc4, 128];
        yield 'AESV2' => [Sec\PublicKeyCipher::Aes128, 128];
        yield 'AESV3' => [Sec\PublicKeyCipher::Aes256, 256];
    }

    #[DataProvider('cryptCiphers')]
    public function testEveryDocumentCryptCipherRequiresPdf20MostSignificantFirst(Sec\PublicKeyCipher $cipher, int $bits): void
    {
        $operation = $this->cryptOperation($cipher, $this->material($bits, Sec\PublicKeyPermissionByteOrder::IsoLeastSignificantFirst, $this->permissions()));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('most-significant-byte-first');
        $this->validate($operation);
    }

    /** @return iterable<string, array{Sec\PublicKeySubFilter, int}> */
    public static function legacyFilters(): iterable
    {
        yield 's3 V1 RC4-40' => [Sec\PublicKeySubFilter::Pkcs7S3, 40];
        yield 's4 V2 RC4-128' => [Sec\PublicKeySubFilter::Pkcs7S4, 128];
    }

    #[DataProvider('legacyFilters')]
    public function testEveryLegacyPublicKeyFilterStillRequiresPdf20MsbOrder(Sec\PublicKeySubFilter $subfilter, int $bits): void
    {
        $operation = $this->legacyOperation($subfilter, $this->material($bits, Sec\PublicKeyPermissionByteOrder::IsoLeastSignificantFirst, $this->permissions()));
        $this->validate($operation, '1.7');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('most-significant-byte-first');
        $this->validate($operation);
    }

    #[DataProvider('legacyFilters')]
    public function testLegacyPublicKeyAlgorithmsRemainPermittedWithPdf20PermissionFacts(Sec\PublicKeySubFilter $subfilter, int $bits): void
    {
        $operation = $this->legacyOperation($subfilter, $this->material($bits, permissions: $this->permissions()));
        $this->validate($operation);
        self::assertSame($subfilter->value, $operation->dictionary()->get('SubFilter')?->value);
    }

    public function testNativeAes256DoesNotNeedTheHistoricalAdobeDeclarationInPdf20(): void
    {
        $operation = $this->cryptOperation(Sec\PublicKeyCipher::Aes256, $this->material(permissions: $this->permissions()));
        $this->validate($operation);
        $this->validate($operation, '1.7', '2.0');
        self::assertSame(5, $operation->version);
    }

    public function testAes256StillRequiresTheAdobeDeclarationBeforePdf20(): void
    {
        $operation = $this->cryptOperation(Sec\PublicKeyCipher::Aes256, $this->material(order: Sec\PublicKeyPermissionByteOrder::IsoLeastSignificantFirst));
        $this->validate($operation, '1.7', adobe: true);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Adobe 1.7 ExtensionLevel 3');
        $this->validate($operation, '1.7');
    }

    public function testOpaqueDocumentCmsNeedsExplicitFactsInPdf20(): void
    {
        $operation = $this->cryptOperation(Sec\PublicKeyCipher::Aes256, $this->material());
        $this->validate($operation, '1.7', adobe: true);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit payload permission facts');
        $this->validate($operation);
    }

    /** @return iterable<string, array{int, string}> */
    public static function invalidPermissions(): iterable
    {
        yield 'accessibility cleared' => [0xfffffdfc, 'bit10'];
        foreach ([13, 19, 31, 32] as $bit) {
            yield 'reserved bit' . $bit . ' cleared' => [0xfffffffc & ~(1 << ($bit - 1)), 'bits13–32'];
        }
    }

    #[DataProvider('invalidPermissions')]
    public function testPdf20PermissionRequirementsApplyToTheCmsFacts(int $word, string $message): void
    {
        $operation = $this->cryptOperation(Sec\PublicKeyCipher::Aes256, $this->material(permissions: $this->permissions($word)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        $this->validate($operation);
    }

    public function testAllRecipientPermissionGroupsAreCheckedEvenWhenFirstIsValid(): void
    {
        $material = new Sec\PublicKeyKeyMaterial(new Sec\PublicKeySeed(new HexadecimalString(str_repeat('s', 20))), new Sec\PublicKeyKeyLength(new IntegerType(256)), new Sec\EncryptMetadata(true), Sec\PublicKeyRecipientScope::Document, Sec\PublicKeyPermissionByteOrder::MostSignificantFirst, new Sec\PublicKeyRecipient(new HexadecimalString('first CMS'), $this->permissions()), new Sec\PublicKeyRecipient(new HexadecimalString('second CMS'), $this->permissions(2)));
        $operation = $this->cryptOperation(Sec\PublicKeyCipher::Aes256, $material);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('bit10');
        $this->validate($operation);
    }

    public function testHistoricalTopLevelPDoesNotEstablishCmsPermissions(): void
    {
        $operation = $this->cryptOperation(Sec\PublicKeyCipher::Aes256, $this->material(permissions: $this->permissions()));
        $this->validate($operation);
        self::assertSame(0, $operation->dictionary()->get('P')?->value);
        self::assertSame(0xfffffffc, $operation->filters->filters()[0]->material->recipients[0]->permissions?->unsigned);
    }

    public function testSeedOnlyIndividualStreamDoesNotHaveAPermissionByteOrderRequirement(): void
    {
        $operation = $this->cryptOperation(Sec\PublicKeyCipher::Aes256, $this->material(order: Sec\PublicKeyPermissionByteOrder::IsoLeastSignificantFirst, scope: Sec\PublicKeyRecipientScope::IndividualStream));
        $this->validate($operation);
        self::assertNull($operation->filters->filters()[0]->material->recipients[0]->permissions);
    }

    public function testIndividualStreamCannotDeclareANonexistentPermissionWord(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('omit document permission words');
        $this->material(permissions: $this->permissions(), scope: Sec\PublicKeyRecipientScope::IndividualStream);
    }

    public function testHandlerPreservesThePermissionWordActuallySuppliedToTheCmsBackend(): void
    {
        $backend = new class () implements Sec\CmsEnvelopeBackend {
            public array $payloads = [];
            public function envelope(string $payload, Sec\X509Certificate ...$certificates): Sec\PublicKeyRecipient
            {
                $this->payloads[] = $payload;
                return new Sec\PublicKeyRecipient(new HexadecimalString('opaque CMS:' . hash('sha256', $payload)));
            }
        };
        $handler = new Sec\PublicKeySecurityHandler($backend, Sec\PublicKeyPermissionByteOrder::MostSignificantFirst, aes256: new Sec\OpenSslAes256Backend());
        $permissions = $this->permissions();
        $certificate = new Sec\X509Certificate(new HexadecimalString('explicit certificate bytes'));
        $filter = $handler->createFilter(new NameType('DefaultCryptFilter'), Sec\PublicKeyCipher::Aes256, new Sec\PublicKeyKeyLength(new IntegerType(256)), new Sec\EncryptMetadata(true), Sec\PublicKeyRecipientScope::Document, new NameType('DocOpen'), new Sec\PublicKeyRecipientGroup($permissions, $certificate));
        self::assertSame($permissions, $filter->material->recipients[0]->permissions);
        self::assertSame('fffffffc', bin2hex(substr($backend->payloads[0], 20)));
        self::assertSame(24, strlen($backend->payloads[0]));
    }

    public function testBackendCannotDeclarePermissionsContradictingItsSuppliedPayload(): void
    {
        $backend = new class () implements Sec\CmsEnvelopeBackend {
            public function envelope(string $payload, Sec\X509Certificate ...$certificates): Sec\PublicKeyRecipient
            {
                return new Sec\PublicKeyRecipient(new HexadecimalString('opaque CMS'), new Sec\PublicKeyPermissions(new IntegerType(2)));
            }
        };
        $handler = new Sec\PublicKeySecurityHandler($backend, Sec\PublicKeyPermissionByteOrder::MostSignificantFirst, aes256: new Sec\OpenSslAes256Backend());
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('different from its supplied payload');
        $handler->createFilter(new NameType('DefaultCryptFilter'), Sec\PublicKeyCipher::Aes256, new Sec\PublicKeyKeyLength(new IntegerType(256)), new Sec\EncryptMetadata(true), Sec\PublicKeyRecipientScope::Document, new NameType('DocOpen'), new Sec\PublicKeyRecipientGroup($this->permissions(), new Sec\X509Certificate(new HexadecimalString('certificate bytes'))));
    }

    public function testExplicitAuthenticationChecksThatDeclaredFactsMatchTheDecryptedWord(): void
    {
        $material = $this->material(permissions: $this->permissions());
        $decrypter = new class () implements Sec\RecipientDecrypter {
            public function decrypt(Sec\PublicKeyRecipient $recipient): ?string
            {
                return str_repeat('s', 20) . pack('N', 0xfffff2ff);
            }
        };
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('differ from the explicitly declared');
        $material->authenticate($decrypter);
    }
}
