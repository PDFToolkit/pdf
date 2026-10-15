<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Signatures as Sig;
use PDF\Document\Signatures\Policies\UsageRight;
use PDF\Tests\Support\EncryptedUsageRightsFixture;
use PDF\Tests\Support\UsageRightsEncryptionCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncryptedUsageRightsTest extends TestCase
{
    /** @return iterable<string, array{string, bool, string, bool, bool}> */
    public static function credentials(): iterable
    {
        foreach (['r2', 'r3', 'r4', 'r4aes', 'r5', 'r6', 'r7', 's3', 's4', 's5rc4', 's5aes', 's5aes256', 's5gcm'] as $kind) {
            yield $kind . ' user fill' => [$kind, true, 'user', false, true];
            yield $kind . ' denied user' => [$kind, false, 'user', false, false];
            yield $kind . ' wrong credential' => [$kind, true, 'bad', false, false];
            yield $kind . ' actual owner' => [$kind, false, 'owner', true, true];
        }
    }

    #[DataProvider('credentials')]
    public function testRealAuthenticationAndPermissionIntersection(string $kind, bool $fill, string $credential, bool $allAccess, bool $allowed): void
    {
        $credentials = new UsageRightsEncryptionCredentials($kind, $fill, $credential, $allAccess);
        $fixture = new EncryptedUsageRightsFixture($credentials->encryption, $credentials->version);
        if (!$allowed) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage($credential === 'bad' ? 'authentication failed' : 'security-handler permissions');
        }
        $next = $fixture->fill($credentials->authentication);
        self::assertStringStartsWith($fixture->revision->bytes, $next->bytes);
        self::assertSame('changed', $next->binding($fixture->field)->dictionary->get('V')->value);
        self::assertSame($credentials->encryption, $next->encryption);
        self::assertSame($fixture->revision->verifiedSignature, $next->previous->verifiedSignature);
    }

    public function testAuthenticationCannotBeOmittedOrInheritedByTheNextUpdate(): void
    {
        $credentials = new UsageRightsEncryptionCredentials('r6');
        $fixture = new EncryptedUsageRightsFixture($credentials->encryption);
        foreach ([$fixture->revision, $fixture->fill($credentials->authentication)] as $source) {
            try {
                $fixture->fill(null, $source);
                self::fail('Every update requires its own explicit authentication.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('per-update security-handler authentication', $error->getMessage());
            }
        }
    }

    public function testCredentialsForAnotherHandlerCannotBeReused(): void
    {
        $credentials = new UsageRightsEncryptionCredentials('r6');
        $other = new UsageRightsEncryptionCredentials('s5aes');
        $fixture = new EncryptedUsageRightsFixture($credentials->encryption);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('authentication failed');
        $fixture->fill($other->authentication);
    }


    public function testActualNamedCryptFiltersAuthenticateSeparatelyAndIntersectDocumentPermissions(): void
    {
        $credentials = new UsageRightsEncryptionCredentials('s5aes');
        $handler = new \PDF\Security\PublicKeySecurityHandler(new \PDF\Security\OpenSslCmsEnvelopeBackend(), \PDF\Security\PublicKeyPermissionByteOrder::IsoLeastSignificantFirst, aes128: new \PDF\Security\OpenSslAes128Backend());
        $name = static fn (string $v) => new \PDF\Syntax\Object\NameType($v);
        $make = function (string $filter, string $identity, ?int $permissions) use ($handler, $credentials, $name) {
            return $handler->createFilter(
                $name($filter),
                \PDF\Security\PublicKeyCipher::Aes128,
                new \PDF\Security\PublicKeyKeyLength(new \PDF\Syntax\Object\IntegerType(128)),
                new \PDF\Security\EncryptMetadata(true),
                $permissions === null ? \PDF\Security\PublicKeyRecipientScope::IndividualStream : \PDF\Security\PublicKeyRecipientScope::Document,
                $name($permissions === null ? 'EFOpen' : 'DocOpen'),
                new \PDF\Security\PublicKeyRecipientGroup($permissions === null ? null : new \PDF\Security\PublicKeyPermissions(new \PDF\Syntax\Object\IntegerType($permissions)), $credentials->certificate($identity))
            );
        };
        $strings = $make('Strings', 'signer', 256);
        $streams = $make('Streams', 'recipient', 0);
        $attachment = $make('Attachment', 'other-recipient', null);
        $encryption = new \PDF\Security\PublicKeyEncryption(new \PDF\Security\PublicKeyFilterConfiguration($name('Strings'), $name('Streams'), $name('Attachment'), $strings, $streams, $attachment), new \PDF\Security\PublicKeyPermissions(new \PDF\Syntax\Object\IntegerType(-4)));
        $authentication = new Sig\CertificateUsageRightsAuthentication($credentials->decrypter('signer'), new Sig\CryptFilterUsageRightsCredential($name('Streams'), $credentials->decrypter('recipient')), new Sig\CryptFilterUsageRightsCredential($name('Attachment'), $credentials->decrypter('other-recipient')));
        $permissions = $authentication->authenticate($encryption);
        self::assertCount(2, $permissions); // The 20-byte individual-stream payload has no document permission word.
        self::assertTrue($permissions[0]->allows(UsageRight::FillField));
        self::assertFalse($permissions[1]->allows(UsageRight::FillField));
        $fixture = new EncryptedUsageRightsFixture($encryption, '1.7');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('security-handler permissions');
        $fixture->fill($authentication);
    }

    public function testDecrypterErrorsCannotLeakBackendDetails(): void
    {
        $credentials = new UsageRightsEncryptionCredentials('s5aes');
        $authentication = new Sig\CertificateUsageRightsAuthentication(new class () implements \PDF\Security\RecipientDecrypter {
            public function decrypt(\PDF\Security\PublicKeyRecipient $recipient): ?string
            {
                throw new \RuntimeException('private credential error details');
            }
        });
        try {
            $authentication->authenticate($credentials->encryption);
            self::fail('Failed decryption cannot establish authority.');
        } catch (\LogicException $error) {
            self::assertStringNotContainsString('private credential', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }


    public function testRecipientPayloadMustRetainItsActualSeedAndDeclaredPermissionWord(): void
    {
        $credentials = new UsageRightsEncryptionCredentials('s5aes');
        foreach ([0, 23] as $changedByte) {
            $authentication = new Sig\CertificateUsageRightsAuthentication(new class ($credentials->decrypter('signer'), $changedByte) implements \PDF\Security\RecipientDecrypter {
                public function __construct(private \PDF\Security\RecipientDecrypter $real, private int $offset)
                {
                }
                public function decrypt(\PDF\Security\PublicKeyRecipient $recipient): ?string
                {
                    $payload = $this->real->decrypt($recipient);
                    $payload[$this->offset] = chr(ord($payload[$this->offset]) ^ 1);
                    return $payload;
                }
            });
            try {
                $authentication->authenticate($credentials->encryption);
                self::fail('Changed CMS seed/permission facts cannot authorize an update.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('authentication failed', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }
    }

    public function testAbsentNamedFilterCredentialsCannotSilentlyUseAnotherFilterKey(): void
    {
        $credentials = new UsageRightsEncryptionCredentials('s5aes');
        $authentication = new Sig\CertificateUsageRightsAuthentication($credentials->decrypter('signer'), new Sig\CryptFilterUsageRightsCredential(new \PDF\Syntax\Object\NameType('Absent'), $credentials->decrypter('signer')));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('authentication failed');
        $authentication->authenticate($credentials->encryption);
    }

    /** @return iterable<string, array{bool, bool, bool, string}> */
    public static function newFields(): iterable
    {
        yield 'missing signed add' => [false, true, false, 'owner'];
        yield 'missing application add' => [true, false, false, 'owner'];
        yield 'both authorities add' => [true, true, true, 'owner'];
        yield 'user fill cannot create' => [true, true, false, 'user'];
    }

    #[DataProvider('newFields')]
    public function testNewSignatureFieldsRequireTheirOwnCreationRight(bool $signed, bool $application, bool $allowed, string $access): void
    {
        $credentials = new UsageRightsEncryptionCredentials('r6', true, $access);
        $fixture = new EncryptedUsageRightsFixture($credentials->encryption, addField: $signed);
        $source = $fixture->revision;
        $graph = new Sig\FrozenSignatureGraph($source);
        $form = $source->binding($graph->catalog->get('AcroForm')->target());
        $signing = new Sig\SignatureFieldSigning((new \PDF\Tests\Support\ProtectedRevisionFixture())->backend, new \PDF\Document\Forms\PartialFieldName(new \PDF\Syntax\DataStructure\TextString('new-signature')), new Sig\SignatureCapacity(8192));
        $fields = $form->dictionary->get('Fields');
        $authorization = new Sig\UsageRightsAuthorization($fixture->authority, UsageRight::ModifySignature, $credentials->authentication, ...($application ? [UsageRight::AddField] : []));
        $update = new \PDF\Syntax\FileStructure\IncrementalUpdate($source, new \PDF\Syntax\FileStructure\FileBody(new \PDF\Syntax\Object\IndirectObject($form->dictionary->with(new \PDF\Syntax\Object\DictionaryObject\Entry(new \PDF\Syntax\Object\NameType('Fields'), new \PDF\Syntax\Object\ArrayType(...[...$fields->items, new \PDF\Syntax\Object\IndirectReference($signing->field)]))), $form->identifier)), null, null, $authorization);
        if (!$allowed) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage($access === 'owner' ? 'explicit application scope' : 'security-handler permissions');
        }
        $result = $signing->sign(new Sig\PreparedSignature($update->toRevision(), $signing->signature));
        self::assertNotNull($result->revision->verifiedSignature);
        self::assertStringStartsWith($source->bytes, $result->revision->bytes);
    }

    /** @return iterable<string, array{int, bool, UsageRight, bool}> */
    public static function permissions(): iterable
    {
        yield 'r2 bit9 reserved' => [-64, true, UsageRight::FillField, false];
        yield 'r2 bit11 reserved' => [-64, true, UsageRight::SpawnTemplate, false];
        yield 'r2 annotation fills' => [32, true, UsageRight::FillField, true];
        yield 'r3 fill existing' => [256, false, UsageRight::FillField, true];
        yield 'r3 annotation fills' => [32, false, UsageRight::FillField, true];
        yield 'r3 existing signature' => [256, false, UsageRight::ModifySignature, true];
        yield 'r3 fill cannot create field' => [256, false, UsageRight::AddField, false];
        yield 'r3 modification alone cannot create field' => [8, false, UsageRight::AddField, false];
        yield 'r3 field creation' => [40, false, UsageRight::AddField, true];
        yield 'r3 field deletion' => [40, false, UsageRight::DeleteField, true];
        yield 'r3 assemble' => [1024, false, UsageRight::SpawnTemplate, true];
        yield 'r3 modify assembles' => [8, false, UsageRight::SpawnTemplate, true];
        yield 'r3 fill cannot annotate' => [256, false, UsageRight::CreateAnnotation, false];
        yield 'r3 annotation modification' => [32, false, UsageRight::ModifyAnnotation, true];
        yield 'r3 annotation deletion' => [32, false, UsageRight::DeleteAnnotation, true];
    }

    #[DataProvider('permissions')]
    public function testOperationBitsFollowTheirActualRevision(int $word, bool $revision2, UsageRight $operation, bool $expected): void
    {
        self::assertSame($expected, (new Sig\UsageRightsSecurityPermissions($word, $revision2, false))->allows($operation));
    }
}
