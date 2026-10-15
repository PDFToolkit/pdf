<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Extensions\IsoExtensions;
use PDF\Document\Extensions\IsoTechnicalSpecification;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\SignatureEllipticCurve;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureVerificationInput;
use PDF\Security\AesGcmCipher;
use PDF\Security\AesKeyWrap;
use PDF\Security\CertificateChain;
use PDF\Security\EncryptMetadata;
use PDF\Security\FileEncryptionKey;
use PDF\Security\ModernCmsSignatureBackend;
use PDF\Security\ModernSecurityRevision;
use PDF\Security\ModernStandardSecurityHandler;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\OpenSslEdDsaSignaturePrimitive;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslModernSignaturePrimitive;
use PDF\Security\PasswordAccess;
use PDF\Security\Pdf20Permissions;
use PDF\Security\PdfMacSignatureAttachment;
use PDF\Security\PdfMacTokenComposer;
use PDF\Security\PdfMacTokenVerifier;
use PDF\Security\PreparedPassword;
use PDF\Security\RandomSource;
use PDF\Security\Shake256;
use PDF\Security\SystemRandomSource;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Iso32000CryptographyTest extends TestCase
{
    public function testFixedShake256MatchesNistKnownAnswerAndCrossesRateBoundary(): void
    {
        $shake = new Shake256();
        self::assertSame('46b9dd2b0ba88d13233b3feb743eeb243fcd52ea62b81b82b50c27646ed5762fd75dc4ddd8c0f200cb05019d67b592f6fc821c49479ab48640292eacb3b7c4be', bin2hex($shake->digest('')));
        self::assertSame('cd8a920ed141aa0407a22d59288652e9d9f1a7ee0c1e7c1ca699424da84a904d2d700caae7396ece96604440577da4f3aa22aeb8857f961c4cd8e06f0ae6610b', bin2hex($shake->digest(str_repeat("\xa3", 200))));
        self::assertSame(64, strlen(SignatureDigestMethod::SHAKE256->digest('anything')));
    }

    public function testRfc3394KnownAnswerAndWrappedKeyTampering(): void
    {
        $wrap = new AesKeyWrap();
        $kek = new FileEncryptionKey(hex2bin('000102030405060708090A0B0C0D0E0F101112131415161718191A1B1C1D1E1F'));
        $key = hex2bin('00112233445566778899AABBCCDDEEFF');
        $encrypted = $wrap->wrap($key, $kek);
        self::assertSame('64e8c3f9ce0f5ba263e9777905818a2a93c8191e7d6e8ae7', bin2hex($encrypted));
        self::assertSame($key, $wrap->unwrap($encrypted, $kek));
        $encrypted[8] = chr(ord($encrypted[8]) ^ 1);
        $this->expectException(\UnexpectedValueException::class);
        $wrap->unwrap($encrypted, $kek);
    }

    public function testAes256GcmNistVectorAndAuthenticationBeforeReturningPlaintext(): void
    {
        $cipher = new AesGcmCipher();
        $key = new FileEncryptionKey(str_repeat("\0", 32));
        $encrypted = $cipher->encrypt(str_repeat("\0", 16), $key, str_repeat("\0", 12));
        self::assertSame('cea7403d4d606b6e074ec5d3baf39d18d0d1c8a799996bf0265b98b5d48ab919', bin2hex(substr($encrypted, 12)));
        self::assertSame(str_repeat("\0", 16), $cipher->decrypt($encrypted, $key));
        $encrypted[12] = chr(ord($encrypted[12]) ^ 1);
        $this->expectException(\UnexpectedValueException::class);
        $cipher->decrypt($encrypted, $key);
    }

    /** @return iterable<string,array{ModernSecurityRevision}> */
    public static function revisions(): iterable
    {
        foreach (ModernSecurityRevision::cases() as $revision) {
            yield $revision->name => [$revision];
        }
    }

    #[DataProvider('revisions')]
    public function testModernPasswordAuthenticationAndPerFileCipherOperation(ModernSecurityRevision $revision): void
    {
        $handler = new ModernStandardSecurityHandler(new OpenSslAes256Backend(), new SystemRandomSource());
        $encryption = $handler->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Pdf20Permissions(-4), new EncryptMetadata(false), $revision);
        self::assertSame(PasswordAccess::User, $handler->authenticate(new PreparedPassword('user'), $encryption->credentials)?->access);
        self::assertSame(PasswordAccess::Owner, $handler->authenticate(new PreparedPassword('owner'), $encryption->credentials)?->access);
        self::assertNull($handler->authenticate(new PreparedPassword('wrong'), $encryption->credentials));
        $nonces = [];
        for ($index = 0; $index < 32; ++$index) {
            $ciphertext = $encryption->encrypt('same plaintext');
            self::assertSame('same plaintext', $encryption->decrypt($ciphertext));
            $nonces[] = substr($ciphertext, 0, $revision === ModernSecurityRevision::Aes256Gcm ? 12 : 16);
        }
        self::assertCount(32, array_unique($nonces));
        self::assertSame($revision->value, $encryption->dictionary()->get('R')->value);
    }

    /** @return iterable<string,array{SignatureDigestMethod}> */
    public static function macDigests(): iterable
    {
        foreach ([SignatureDigestMethod::SHA256, SignatureDigestMethod::SHA384, SignatureDigestMethod::SHA512, SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512] as $digest) {
            yield $digest->value => [$digest];
        }
    }

    #[DataProvider('macDigests')]
    public function testPdfMacAuthenticatesExactDocumentAndSignatureBindings(SignatureDigestMethod $digest): void
    {
        $key = new FileEncryptionKey(str_repeat('K', 32));
        $composer = new PdfMacTokenComposer($key, str_repeat('S', 32), new SystemRandomSource(), $digest);
        $verifier = new PdfMacTokenVerifier($key, str_repeat('S', 32));
        $token = $composer->token('document');
        self::assertSame($composer->tokenLength(), strlen($token));
        $verifier->verify($token, 'document');
        $signed = $composer->token('document', 'raw-signature');
        self::assertSame($composer->tokenLength(true), strlen($signed));
        $verifier->verify($signed, 'document', 'raw-signature');
        $this->expectException(\UnexpectedValueException::class);
        $verifier->verify($signed, 'document', 'replaced-signature');
    }

    public function testPdfMacRejectsChangedDocumentAndChangedHmacWithoutPadding(): void
    {
        $composer = new PdfMacTokenComposer(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32), new SystemRandomSource());
        $token = $composer->token('document');
        foreach ([$token, $token . "\0", substr($token, 0, -1) . chr(ord($token[strlen($token) - 1]) ^ 1)] as $candidate) {
            try {
                $composer->verify($candidate, 'changed');
                self::fail('A modified document or padded token cannot authenticate.');
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function testIsoExtensionsPreserveIndependentLevelsAndRequireExactRevision(): void
    {
        $extensions = new IsoExtensions(IsoTechnicalSpecification::HashAlgorithms, IsoTechnicalSpecification::DigitalSignatures);
        $catalog = new DictionaryType(new Entry(new NameType('Extensions'), $extensions));
        IsoTechnicalSpecification::HashAlgorithms->validate($catalog);
        IsoTechnicalSpecification::DigitalSignatures->validate($catalog);
        self::assertCount(2, $extensions->get('ISO_')->items);
        $this->expectException(\LogicException::class);
        IsoTechnicalSpecification::AesGcm->validate($catalog);
    }

    /** @return iterable<string,array{string,SignatureDigestMethod}> */
    public static function curveDigests(): iterable
    {
        foreach (SignatureEllipticCurve::cases() as $curve) {
            foreach ($curve->digests() as $digest) {
                yield $curve->value . ' ' . $digest->value => [$curve->value, $digest];
            }
        }
        foreach ([SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512] as $digest) {
            yield 'RSA ' . $digest->value => ['RSA', $digest];
        }
    }

    #[DataProvider('curveDigests')]
    public function testRealModernCmsSignaturesAndAttachedMacForEverySpecifiedCurveDigest(string $name, SignatureDigestMethod $digest): void
    {
        $identity = $this->identity($name);
        $primitive = str_starts_with($name, 'Ed') ? new OpenSslEdDsaSignaturePrimitive($this->openssl(), $this->privateKey($name)) : new OpenSslModernSignaturePrimitive();
        $backend = new ModernCmsSignatureBackend($identity, $digest, $primitive);
        $signature = $backend->sign('exact PDF byte range', SignatureFormat::Pkcs7Detached);
        $input = new SignatureVerificationInput('exact PDF byte range', $signature, SignatureFormat::Pkcs7Detached, $identity->chain);
        self::assertSame($digest, $backend->verify($input)->algorithms->digest);
        $mac = new PdfMacTokenComposer(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32), new SystemRandomSource());
        $attachment = new PdfMacSignatureAttachment();
        $attached = $attachment->attach($signature, $input->coveredBytes, $mac);
        $attachment->verify($attached, $input->coveredBytes, $mac);
        $attachment->verify($attached, $input->coveredBytes, new PdfMacTokenVerifier(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32)));
        self::assertSame($attachment->signatureValue($signature), $attachment->signatureValue($attached));
        self::assertSame($digest, $backend->verify(new SignatureVerificationInput($input->coveredBytes, $attached, $input->format, $input->certificate))->algorithms->digest);
        $this->expectException(\InvalidArgumentException::class);
        $backend->verify(new SignatureVerificationInput('different byte range', $signature, $input->format, $input->certificate));
    }

    public function testCurveDigestMismatchRejectedBeforeSigning(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModernCmsSignatureBackend($this->identity('P-256'), SignatureDigestMethod::SHA512);
    }

    public function testModifiedMacBytesFailEvenWhenTheDocumentBindingIsUnchanged(): void
    {
        $composer = new PdfMacTokenComposer(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32), new SystemRandomSource());
        $token = $composer->token('document');
        $token[strlen($token) - 1] = chr(ord($token[strlen($token) - 1]) ^ 1);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('CMS authentication');
        $composer->verify($token, 'document');
    }

    public function testMacKeyMaterialIsRedactedFromDebugOutput(): void
    {
        $composer = new PdfMacTokenComposer(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32), new SystemRandomSource());
        ob_start();
        var_dump($composer);
        $debug = ob_get_clean();
        self::assertStringContainsString('SHA256', $debug);
        self::assertStringNotContainsString('macKey', $debug);
        self::assertStringNotContainsString('wrappedKey', $debug);
    }

    public function testIso32003CannotUseTheSingularLegacyExtensionForm(): void
    {
        $catalog = new DictionaryType(new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ISO_'), IsoTechnicalSpecification::AesGcm->dictionary()))));
        $this->expectException(\LogicException::class);
        IsoTechnicalSpecification::AesGcm->validate($catalog);
    }

    public function testMacInputBudgetIsCheckedBeforeDerParsing(): void
    {
        $composer = new PdfMacTokenComposer(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32), new SystemRandomSource());
        $this->expectException(\LengthException::class);
        $composer->verify(str_repeat('x', 1048577), 'document');
    }

    public function testEc3DeletedSignatureReferenceHashExtensionIsNotReintroduced(): void
    {
        self::assertSame(['MD5', 'SHA1'], array_map(static fn ($digest): string => $digest->value, \PDF\Document\Signatures\Policies\SignatureReferenceDigest::cases()));
    }

    public function testMacPermissionBitCannotBeSilentlyTreatedAsReserved(): void
    {
        self::assertSame(-4100, (new Pdf20Permissions(-4100, true))->value);
        $this->expectException(\InvalidArgumentException::class);
        new Pdf20Permissions(-4100);
    }

    private function identity(string $name): OpenSslIdentity
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/Fixtures/iso32000-crypto/keys.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures['keys'] as $fixture) {
            if ($fixture['name'] === $name) {
                return new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString(base64_decode($fixture['certificate'], true)))), $fixture['private_key']);
            }
        }
        throw new \LogicException('Unknown public test credential.');
    }

    private function openssl(): string
    {
        foreach ([getenv('PDFTOOLKIT_OPENSSL'), '/usr/bin/openssl', 'C:/Program Files/Git/mingw64/bin/openssl.exe'] as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }
        self::markTestSkipped('The explicitly selected EdDSA provider requires an OpenSSL executable.');
    }

    private function privateKey(string $name): string
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/Fixtures/iso32000-crypto/keys.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures['keys'] as $fixture) {
            if ($fixture['name'] === $name) {
                return $fixture['private_key'];
            }
        }
        throw new \LogicException('Unknown public test credential.');
    }
}
