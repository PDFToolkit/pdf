<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Security\CmsDerEncoding;
use PDF\Security\FileEncryptionKey;
use PDF\Security\PdfMacTokenComposer;
use PDF\Security\PdfMacTokenVerifier;
use PDF\Security\RandomSource;
use PDF\Security\SignatureDerElement;
use PDF\Security\SignatureDerReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** RFC5754 §2 and RFC6211 §3.2; every altered authenticated SET receives a real recomputed HMAC. */
final class PdfMacAlgorithmIdentifierTest extends TestCase
{
    /**
     * @param \Closure(list<SignatureDerElement>,CmsDerEncoding,SignatureDerReader):list<string> $change
     */
    private function token(SignatureDigestMethod $method, \Closure $change): string
    {
        $random = new class () implements RandomSource {
            public function bytes(int $length): string
            {
                return str_repeat('M', $length);
            }
        };
        $composer = new PdfMacTokenComposer(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32), $random, $method);
        $reader = new SignatureDerReader();
        $der = new CmsDerEncoding();
        $outer = $reader->element($composer->token('document'))->children();
        $data = $outer[1]->children()[0]->children();
        $encoded = $change($data, $der, $reader);
        $attributes = $reader->element($encoded[5])->requireTag(162);
        $encoded[6] = $der->octets(hash_hmac('sha256', $der->element(49, $attributes->value), str_repeat('M', 32), true));
        return $der->sequence($outer[0]->encoding, $der->element(160, $der->sequence(...$encoded)));
    }

    /** @return list<string> */
    private function encodings(SignatureDerElement ...$elements): array
    {
        $encoded = [];
        foreach ($elements as $element) {
            $encoded[] = $element->encoding;
        }
        return $encoded;
    }

    /**
     * @param list<SignatureDerElement> $data
     * @param list<string> $replacement
     * @return list<string>
     */
    private function attributes(array $data, CmsDerEncoding $der, array $replacement, bool $keepProtection = false): array
    {
        $attributes = [];
        foreach ($data[5]->children() as $attribute) {
            $oid = $attribute->children()[0]->encoding;
            if ($keepProtection || $oid !== $der->oid('1.2.840.113549.1.9.52')) {
                $attributes[] = $attribute->encoding;
            }
        }
        $set = $der->set(...[...$attributes, ...$replacement]);
        $encoded = $this->encodings(...$data);
        $encoded[5] = $der->element(162, (new SignatureDerReader())->element($set)->value);
        return array_values($encoded);
    }

    private function verify(string $token): void
    {
        (new PdfMacTokenVerifier(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32)))->verify($token, 'document');
    }

    /** @return iterable<string,array{SignatureDigestMethod,bool,bool}> */
    public static function sha2ParameterForms(): iterable
    {
        foreach ([SignatureDigestMethod::SHA256, SignatureDigestMethod::SHA384, SignatureDigestMethod::SHA512] as $method) {
            foreach ([false, true] as $outer) {
                foreach ([false, true] as $protected) {
                    yield $method->name . ':' . (int) $outer . ':' . (int) $protected => [$method, $outer, $protected];
                }
            }
        }
    }

    #[DataProvider('sha2ParameterForms')]
    public function testSha2AbsentAndNullParametersAreSemanticallyEquivalent(SignatureDigestMethod $method, bool $outerNull, bool $protectedNull): void
    {
        $token = $this->token($method, function (array $data, CmsDerEncoding $der, SignatureDerReader $reader) use ($method, $outerNull, $protectedNull): array {
            $algorithm = $der->sequence($der->oid($method->oid()), ...($protectedNull ? ["\x05\x00"] : []));
            $protection = $der->sequence($algorithm, $der->element(162, $data[2]->value));
            $encoded = $this->attributes($data, $der, [$der->sequence($der->oid('1.2.840.113549.1.9.52'), $der->set($protection))]);
            $encoded[3] = $der->element(161, $reader->element($der->sequence($der->oid($method->oid()), ...($outerNull ? ["\x05\x00"] : [])))->value);
            return array_values($encoded);
        });
        $this->verify($token);
        self::assertNotEmpty($token);
    }

    public function testAlgorithmProtectionRemainsOptionalAndUnknownSetAttributesAreAllowed(): void
    {
        $token = $this->token(SignatureDigestMethod::SHA384, fn (array $data, CmsDerEncoding $der): array => $this->attributes($data, $der, [$der->sequence($der->oid('1.2.3.4'), $der->set($der->octets('one'), $der->octets('two')))]));
        $this->verify($token);
        self::assertNotEmpty($token);
    }

    /** @return iterable<string,array{SignatureDigestMethod,string,bool}> */
    public static function invalidDigestParameters(): iterable
    {
        foreach ([SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512] as $method) {
            yield $method->name . ' outer NULL' => [$method, "\x05\x00", false];
            yield $method->name . ' protected NULL' => [$method, "\x05\x00", true];
        }
        yield 'SHA2 non-NULL parameter' => [SignatureDigestMethod::SHA256, "\x04\x00", false];
        yield 'SHA2 duplicate NULL' => [SignatureDigestMethod::SHA256, "\x05\x00\x05\x00", false];
        yield 'SHA2 NULL with contents' => [SignatureDigestMethod::SHA256, "\x05\x01\x00", false];
    }

    #[DataProvider('invalidDigestParameters')]
    public function testParameterEquivalenceIsLimitedToItsSpecifiedDigestForms(SignatureDigestMethod $method, string $parameters, bool $protected): void
    {
        $token = $this->token($method, function (array $data, CmsDerEncoding $der, SignatureDerReader $reader) use ($method, $parameters, $protected): array {
            $algorithm = $der->sequence($der->oid($method->oid()), $parameters);
            if ($protected) {
                return $this->attributes($data, $der, [$der->sequence($der->oid('1.2.840.113549.1.9.52'), $der->set($der->sequence($algorithm, $der->element(162, $data[2]->value))))]);
            }
            $encoded = $this->encodings(...$data);
            $encoded[3] = $der->element(161, $reader->element($algorithm)->value);
            return array_values($encoded);
        });
        $this->expectException(\UnexpectedValueException::class);
        $this->verify($token);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidProtection(): iterable
    {
        foreach (['different-digest', 'different-mac', 'signature-instead', 'both-signature-and-mac', 'extra-field', 'missing-mac', 'multiple-values', 'duplicate-attribute'] as $attack) {
            yield $attack => [$attack];
        }
    }

    #[DataProvider('invalidProtection')]
    public function testAlgorithmProtectionHasOneMatchingDigestAndMacOnly(string $attack): void
    {
        $token = $this->token(SignatureDigestMethod::SHA256, function (array $data, CmsDerEncoding $der) use ($attack): array {
            $digest = $der->algorithm(($attack === 'different-digest' ? SignatureDigestMethod::SHA384 : SignatureDigestMethod::SHA256)->oid());
            $mac = $attack === 'different-mac' ? $der->oid('1.2.840.113549.2.10') : $data[2]->value;
            $fields = [$digest];
            if (in_array($attack, ['signature-instead', 'both-signature-and-mac'], true)) {
                $fields[] = $der->element(161, $mac);
            }
            if (!in_array($attack, ['missing-mac', 'signature-instead'], true)) {
                $fields[] = $der->element(162, $mac);
            }
            if ($attack === 'extra-field') {
                $fields[] = "\x05\x00";
            }
            $protection = $der->sequence(...$fields);
            $attribute = $der->sequence($der->oid('1.2.840.113549.1.9.52'), $der->set(...($attack === 'multiple-values' ? [$protection, $protection] : [$protection])));
            return $this->attributes($data, $der, [$attribute], $attack === 'duplicate-attribute');
        });
        $this->expectException(\UnexpectedValueException::class);
        $this->verify($token);
    }

    public function testUnknownAuthenticatedAttributeStillRequiresAnAsn1SetOfValues(): void
    {
        $token = $this->token(SignatureDigestMethod::SHA256, fn (array $data, CmsDerEncoding $der): array => $this->attributes($data, $der, [$der->sequence($der->oid('1.2.3.4'), $der->octets('not-a-SET'))]));
        $this->expectException(\InvalidArgumentException::class);
        $this->verify($token);
    }

    /** @return iterable<string,array{SignatureDigestMethod}> */
    public static function unsupportedDigests(): iterable
    {
        foreach ([SignatureDigestMethod::SHA1, SignatureDigestMethod::RIPEMD160, SignatureDigestMethod::SHAKE256] as $method) {
            yield $method->name => [$method];
        }
    }

    #[DataProvider('unsupportedDigests')]
    public function testSha2ParameterHandlingDoesNotAdmitOtherDigestAlgorithms(SignatureDigestMethod $method): void
    {
        $token = $this->token(SignatureDigestMethod::SHA256, function (array $data, CmsDerEncoding $der, SignatureDerReader $reader) use ($method): array {
            $encoded = $this->encodings(...$data);
            $encoded[3] = $der->element(161, $reader->element($der->algorithm($method->oid()))->value);
            return array_values($encoded);
        });
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unsupported PDF MAC digest');
        $this->verify($token);
    }

    /** @return iterable<string,array{string}> */
    public static function strictNonDigestParameters(): iterable
    {
        yield 'MAC' => ['mac'];
        yield 'AES key wrap' => ['wrap'];
        yield 'KDF' => ['kdf'];
    }

    #[DataProvider('strictNonDigestParameters')]
    public function testSha2NullAcceptanceDoesNotRelaxMacWrapOrKdfAlgorithms(string $kind): void
    {
        $token = $this->token(SignatureDigestMethod::SHA256, function (array $data, CmsDerEncoding $der) use ($kind): array {
            $encoded = $this->encodings(...$data);
            if ($kind === 'mac') {
                $encoded[2] = $der->element(48, $data[2]->value . "\x05\x00");
            } else {
                $recipient = $data[1]->children()[0]->children();
                $fields = $this->encodings(...$recipient);
                $index = $kind === 'kdf' ? 1 : 2;
                $fields[$index] = $der->element($recipient[$index]->tag, $recipient[$index]->value . "\x05\x00");
                $encoded[1] = $der->set($der->element(163, implode('', $fields)));
            }
            return array_values($encoded);
        });
        $this->expectException(\UnexpectedValueException::class);
        $this->verify($token);
    }
}
