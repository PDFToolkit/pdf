<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Security\FileEncryptionKey;
use PDF\Security\PdfMacTokenComposer;
use PDF\Security\PdfMacTokenVerifier;
use PDF\Security\SystemRandomSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfMacTokenVerifierTest extends TestCase
{
    public function testReadSideVerificationDoesNotRequireTheComposerOrItsMacKey(): void
    {
        $key = new FileEncryptionKey(str_repeat('K', 32));
        $salt = str_repeat('S', 32);
        $composer = new PdfMacTokenComposer($key, $salt, new SystemRandomSource());
        $bytes = "exact\0covered\xffbytes";
        $token = $composer->token($bytes);
        unset($composer);
        $verifier = new PdfMacTokenVerifier($key, $salt, strlen($token));
        $verifier->verify($token, $bytes);
        $this->expectException(\UnexpectedValueException::class);
        $verifier->verify($token, $bytes . "\0");
    }

    /** @return iterable<string,array{string,string}> */
    public static function wrongCredentials(): iterable
    {
        yield 'wrong key' => [str_repeat('X', 32), str_repeat('S', 32)];
        yield 'wrong salt' => [str_repeat('K', 32), str_repeat('X', 32)];
    }

    #[DataProvider('wrongCredentials')]
    public function testWrongFileKeyOrKdfSaltCannotVerifyAWellFormedMac(string $key, string $salt): void
    {
        $composer = new PdfMacTokenComposer(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32), new SystemRandomSource());
        $token = $composer->token('document');
        $verifier = new PdfMacTokenVerifier(new FileEncryptionKey($key), $salt);
        $this->expectException(\UnexpectedValueException::class);
        $verifier->verify($token, 'document');
    }

    public function testTheTokenBudgetIsEnforcedBeforeAsn1Processing(): void
    {
        $verifier = new PdfMacTokenVerifier(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32), 8);
        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage('byte budget');
        $verifier->verify('not-ASN.1', 'document');
    }

    public function testStandaloneAndSignatureBoundTokensAreNotInterchangeable(): void
    {
        $key = new FileEncryptionKey(str_repeat('K', 32));
        $salt = str_repeat('S', 32);
        $composer = new PdfMacTokenComposer($key, $salt, new SystemRandomSource());
        $verifier = new PdfMacTokenVerifier($key, $salt);
        foreach ([[$composer->token('document'), 'signature'], [$composer->token('document', 'signature'), null]] as [$token, $signature]) {
            try {
                $verifier->verify($token, 'document', $signature);
                self::fail('The MAC must have exactly the selected document/signature binding.');
            } catch (\UnexpectedValueException $error) {
                self::assertStringContainsString('does not bind', $error->getMessage());
            }
        }
    }

    public function testDiagnosticsDoNotExposeTheFileKeySaltOrDerivedWrappingKey(): void
    {
        $key = str_repeat('private-key-', 2) . '12345678';
        $salt = str_repeat('private-salt-', 2) . '123456';
        $verifier = new PdfMacTokenVerifier(new FileEncryptionKey($key), $salt);
        ob_start();
        var_dump($verifier);
        $debug = ob_get_clean();
        self::assertIsString($debug);
        self::assertStringNotContainsString($key, $debug);
        self::assertStringNotContainsString($salt, $debug);
        self::assertStringNotContainsString('kek', $debug);
    }
}
