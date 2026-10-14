<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\TimestampAccuracy;
use PDF\Document\Signatures\TimestampRequest;
use PDF\Document\Signatures\TimestampResponse;
use PDF\Document\Signatures\TimestampSerialSequence;
use PDF\Document\Signatures\TimestampToken;
use PDF\Security\CertificateChain;
use PDF\Security\CmsDerEncoding;
use PDF\Security\LocalTimestampAuthority;
use PDF\Security\OpenSslTimestampTokenVerifier;
use PDF\Security\SignatureDerElement;
use PDF\Security\SignatureDerReader;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Rfc3161ValidationTest extends TestCase
{
    public function testAuthorityDoesNotClaimClockOrderingAndRetainsZeroNonce(): void
    {
        [$authority, $verifier] = $this->authority();
        $request = new TimestampRequest(SignatureDigestMethod::SHA256, hash('sha256', 'actual document', true), nonce: "\0");
        $first = $authority->request($request);
        $second = $authority->request($request);
        $verifier->verify($first, $request);
        self::assertSame("\0", $first->nonce);
        self::assertSame("\x01", $first->serialNumber);
        self::assertSame("\x02", $second->serialNumber);
        self::assertFalse($first->ordering);
    }

    public function testOptionalAccuracyExtensionsAnd160BitSerialRemainExplicitObjects(): void
    {
        [$authority] = $this->authority();
        $request = new TimestampRequest(SignatureDigestMethod::SHA256, hash('sha256', 'document', true));
        $token = $authority->request($request);
        $der = new CmsDerEncoding();
        $info = (new SignatureDerReader())->element($token->tstInfo)->children();
        $fields = array_map(static fn (SignatureDerElement $field): string => $field->encoding, array_slice($info, 0, 5));
        $fields[3] = $der->element(2, "\0" . str_repeat("\xff", 20));
        $fields[] = $der->sequence("\x02\x01\x03", "\x80\x02\x03\xe7", "\x81\x01\x01");
        $fields[] = $der->element(161, $der->sequence($der->oid('1.2.3.4.9'), $der->octets('application-defined')));
        $parsed = $this->rewrite($token, $der->sequence(...$fields));
        self::assertSame(str_repeat("\xff", 20), $parsed->serialNumber);
        self::assertSame(3, $parsed->accuracy->seconds);
        self::assertSame(999, $parsed->accuracy->milliseconds);
        self::assertSame(1, $parsed->accuracy->microseconds);
        self::assertSame('application-defined', $parsed->extensions[0]->value);
        self::assertFalse($parsed->extensions[0]->critical);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidAccuracy(): iterable
    {
        yield 'duplicate seconds' => ["\x30\x06\x02\x01\x01\x02\x01\x02"];
        yield 'wrong order' => ["\x30\x06\x81\x01\x01\x80\x01\x01"];
        yield 'zero milliseconds' => ["\x30\x03\x80\x01\0"];
        yield '1000 milliseconds' => ["\x30\x04\x80\x02\x03\xe8"];
        yield 'negative seconds' => ["\x30\x03\x02\x01\xff"];
        yield 'nonminimal seconds' => ["\x30\x04\x02\x02\0\x01"];
    }

    #[DataProvider('invalidAccuracy')]
    public function testAccuracyRejectsMalformedBoundsAndDer(string $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimestampAccuracy::fromDer((new SignatureDerReader())->element($bytes));
    }

    /** @return iterable<string,array{string}> */
    public static function malformedInfo(): iterable
    {
        foreach (['nonminimal-serial', 'negative-serial', 'nonminimal-nonce', 'empty-extensions', 'duplicate-extensions', 'bad-time', 'bad-imprint', 'duplicate-ordering'] as $attack) {
            yield $attack => [$attack];
        }
    }

    #[DataProvider('malformedInfo')]
    public function testMalformedTstInfoFailsBeforeCryptographicVerification(string $attack): void
    {
        [$authority] = $this->authority();
        $token = $authority->request(new TimestampRequest(SignatureDigestMethod::SHA256, hash('sha256', 'document', true)));
        $der = new CmsDerEncoding();
        $info = (new SignatureDerReader())->element($token->tstInfo)->children();
        $fields = array_map(static fn (SignatureDerElement $field): string => $field->encoding, array_slice($info, 0, 5));
        match ($attack) {
            'nonminimal-serial' => $fields[3] = "\x02\x02\0\x01",
            'negative-serial' => $fields[3] = "\x02\x01\xff",
            'nonminimal-nonce' => $fields[] = "\x02\x02\0\x01",
            'empty-extensions' => $fields[] = "\xa1\0",
            'duplicate-extensions' => $fields[] = $der->element(161, str_repeat($der->sequence($der->oid('1.2.3.4.9'), $der->octets('data')), 2)),
            'bad-time' => $fields[4] = $der->element(24, '20260230000000Z'),
            'bad-imprint' => $fields[2] = $der->sequence($der->algorithm(SignatureDigestMethod::SHA256->oid()), $der->octets('too short')),
            'duplicate-ordering' => $fields[] = "\x01\x01\xff\x01\x01\xff",
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->rewrite($token, $der->sequence(...$fields));
    }

    public function testGrantedStatusIsRequiredBeforeATokenCanBeUsed(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        new TimestampResponse("\x30\x05\x30\x03\x02\x01\x02");
    }

    public function testMathematicallyValidCmsCannotChangeItsEssIssuerSerial(): void
    {
        [$authority, $verifier] = $this->authority();
        $request = new TimestampRequest(SignatureDigestMethod::SHA256, hash('sha256', 'document', true));
        $token = $authority->request($request);
        $der = new CmsDerEncoding();
        $malicious = $this->rewrite($token, $token->tstInfo, function (array $attributes) use ($der, $verifier): array {
            foreach ($attributes as $index => $attribute) {
                $fields = $attribute->children();
                if ($fields[0]->encoding !== $der->oid('1.2.840.113549.1.9.16.2.47')) {
                    continue;
                }
                $values = $fields[1]->children();
                $signing = $values[0]->children();
                $certificates = $signing[0]->children();
                $certificate = $certificates[0]->children();
                $actual = (new SignatureDerReader())->element($verifier->certificateChain()->signer->value->value)->children()[0]->children();
                $issuer = $actual[$actual[0]->tag === 160 ? 3 : 2];
                $issuerSerial = (new SignatureDerReader())->element($der->sequence($der->sequence($der->element(164, $issuer->encoding)), "\x02\x01\x01"));
                if ($certificate[array_key_last($certificate)]->tag === 48) {
                    $certificate[array_key_last($certificate)] = $issuerSerial;
                } else {
                    $certificate[] = $issuerSerial;
                }
                $attributes[$index] = (new SignatureDerReader())->element($der->sequence($fields[0]->encoding, $der->set($der->sequence($der->sequence($der->sequence(...array_map(static fn (SignatureDerElement $item): string => $item->encoding, $certificate)))))));
            }
            return $attributes;
        });
        self::assertSame(1, openssl_verify($der->element(49, $malicious->signerInfo[3]->value), $malicious->signerInfo[5]->value, $verifier->certificateChain()->signer->pem(), OPENSSL_ALGO_SHA256));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('issuerSerial');
        $verifier->verify($malicious, $request);
    }

    /** @param null|callable(list<SignatureDerElement>):list<SignatureDerElement> $attributes */
    private function rewrite(TimestampToken $token, string $tstInfo, ?callable $attributes = null): TimestampToken
    {
        $der = new CmsDerEncoding();
        $reader = new SignatureDerReader();
        $outer = $reader->element($token->bytes)->children();
        $data = $outer[1]->children()[0]->children();
        $data[2] = $reader->element($der->sequence($der->oid('1.2.840.113549.1.9.16.1.4'), $der->element(160, $der->octets($tstInfo))));
        if ($attributes !== null) {
            $signer = $token->signerInfo;
            $signed = $der->set(...array_map(static fn (SignatureDerElement $item): string => $item->encoding, $attributes($signer[3]->children())));
            $fixture = json_decode(file_get_contents(__DIR__ . '/Fixtures/iso32000-crypto/timestamp.json'), true, flags: JSON_THROW_ON_ERROR);
            if (!openssl_sign($signed, $signature, $fixture['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \LogicException('The public test TSA key must sign the adversarial fixture.');
            }
            $signer[3] = $reader->element($der->element(160, $reader->element($signed)->value));
            $signer[5] = $reader->element($der->octets($signature));
            $data[array_key_last($data)] = $reader->element($der->set($der->sequence(...array_map(static fn (SignatureDerElement $item): string => $item->encoding, $signer))));
        }
        return new TimestampToken($der->sequence($outer[0]->encoding, $der->element(160, $der->sequence(...array_map(static fn (SignatureDerElement $item): string => $item->encoding, $data)))));
    }

    /** @return array{LocalTimestampAuthority,OpenSslTimestampTokenVerifier} */
    private function authority(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/Fixtures/iso32000-crypto/timestamp.json'), true, flags: JSON_THROW_ON_ERROR);
        $chain = new CertificateChain(new X509Certificate(new HexadecimalString(base64_decode($fixture['certificate']))));
        foreach ([getenv('PDFTOOLKIT_OPENSSL'), '/usr/bin/openssl', 'C:/Program Files/Git/mingw64/bin/openssl.exe'] as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return [new LocalTimestampAuthority($candidate, $chain, $fixture['private_key'], new TimestampSerialSequence(), '1.2.3.4.1'), new OpenSslTimestampTokenVerifier($chain)];
            }
        }
        self::markTestSkipped('Select an OpenSSL executable for the offline RFC 3161 tests.');
    }
}
