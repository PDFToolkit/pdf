<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\SignatureAlgorithmParameters;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureValue;
use PDF\Document\Signatures\VersionedSignatureBackend;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureKeyAlgorithm;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureAlgorithmVersionTest extends TestCase
{
    private function identity(string $name = 'signer'): OpenSslIdentity
    {
        $certificate = file_get_contents(__DIR__ . '/Fixtures/signing/' . $name . '.der');
        $key = file_get_contents(__DIR__ . '/Fixtures/signing/' . $name . '-private-key.pem');
        return new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString($certificate))), $key);
    }

    private function effective(PdfVersion $header, ?PdfVersion $catalog = null): EffectivePdfVersion
    {
        return new EffectivePdfVersion(new FileHeader($header), new DictionaryType(...($catalog === null ? [] : [new Entry(new NameType('Version'), new NameType($catalog->value))])));
    }

    private function prepared(SignatureFormat $format, PdfVersion $header, ?PdfVersion $catalogVersion = null, string $identity = 'signer'): PreparedSignature
    {
        $entries = $format === SignatureFormat::X509RsaSha1 ? [new Entry(new NameType('Cert'), $this->identity($identity)->chain->object())] : [];
        $pending = new PendingSignature(new SignatureCapacity(8192), $format, new NameType('Adobe.PPKLite'), ...$entries);
        $fields = (new FieldTree())->addRoot(new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending)));
        $catalogEntries = [new Entry(new NameType('AcroForm'), new IndirectReference(new AcroForm($fields)))];
        if ($catalogVersion !== null) {
            $catalogEntries[] = new Entry(new NameType('Version'), new NameType($catalogVersion->value));
        }
        $catalog = new DocumentCatalog(new PageTreeNode(), ...$catalogEntries);
        return (new SignatureWriter())->prepare(new FileStructure(new FileHeader($header), new FileBody($catalog)), $pending);
    }

    /** @return iterable<string, array{SignatureKeyAlgorithm, int, SignatureDigestMethod, SignatureFormat, PdfVersion, bool}> */
    public static function algorithmVersions(): iterable
    {
        yield 'RSA1024 SHA1 requires1.3' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA1, SignatureFormat::X509RsaSha1, PdfVersion::PDF12, false];
        yield 'RSA1024 SHA1 at1.3' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA1, SignatureFormat::X509RsaSha1, PdfVersion::PDF13, true];
        yield 'RSA1025 invalid1.4' => [SignatureKeyAlgorithm::RSA, 1025, SignatureDigestMethod::SHA1, SignatureFormat::X509RsaSha1, PdfVersion::PDF14, false];
        yield 'RSA1025 valid1.5' => [SignatureKeyAlgorithm::RSA, 1025, SignatureDigestMethod::SHA1, SignatureFormat::X509RsaSha1, PdfVersion::PDF15, true];
        yield 'RSA4096 invalid1.4' => [SignatureKeyAlgorithm::RSA, 4096, SignatureDigestMethod::SHA1, SignatureFormat::X509RsaSha1, PdfVersion::PDF14, false];
        yield 'RSA4096 valid1.5' => [SignatureKeyAlgorithm::RSA, 4096, SignatureDigestMethod::SHA1, SignatureFormat::X509RsaSha1, PdfVersion::PDF15, true];
        yield 'DSA1024 invalid1.5' => [SignatureKeyAlgorithm::DSA, 1024, SignatureDigestMethod::SHA1, SignatureFormat::Pkcs7Detached, PdfVersion::PDF15, false];
        yield 'DSA1024 valid1.6' => [SignatureKeyAlgorithm::DSA, 1024, SignatureDigestMethod::SHA1, SignatureFormat::Pkcs7Detached, PdfVersion::PDF16, true];
        yield 'DSA4096 valid1.6' => [SignatureKeyAlgorithm::DSA, 4096, SignatureDigestMethod::SHA1, SignatureFormat::Pkcs7Detached, PdfVersion::PDF16, true];
        yield 'DSA not PKCS1' => [SignatureKeyAlgorithm::DSA, 1024, SignatureDigestMethod::SHA1, SignatureFormat::X509RsaSha1, PdfVersion::PDF17, false];
        yield 'SHA256 invalid1.5' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA256, SignatureFormat::Pkcs7Detached, PdfVersion::PDF15, false];
        yield 'SHA256 valid1.6' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA256, SignatureFormat::Pkcs7Detached, PdfVersion::PDF16, true];
        yield 'SHA384 invalid1.6' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA384, SignatureFormat::Pkcs7Detached, PdfVersion::PDF16, false];
        yield 'SHA384 valid1.7' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA384, SignatureFormat::Pkcs7Detached, PdfVersion::PDF17, true];
        yield 'SHA512 invalid1.6' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA512, SignatureFormat::Pkcs7Detached, PdfVersion::PDF16, false];
        yield 'SHA512 valid1.7' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA512, SignatureFormat::Pkcs7Detached, PdfVersion::PDF17, true];
        yield 'RIPEMD160 invalid1.6' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::RIPEMD160, SignatureFormat::Pkcs7Detached, PdfVersion::PDF16, false];
        yield 'RIPEMD160 valid1.7' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::RIPEMD160, SignatureFormat::Pkcs7Detached, PdfVersion::PDF17, true];
        yield 'PKCS1 additional digest note a' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA256, SignatureFormat::X509RsaSha1, PdfVersion::PDF16, true];
        yield 'sha1 wrapper CMS digest note b' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA256, SignatureFormat::Pkcs7Sha1, PdfVersion::PDF15, false];
        yield 'sha1 wrapper CMS valid1.6' => [SignatureKeyAlgorithm::RSA, 1024, SignatureDigestMethod::SHA256, SignatureFormat::Pkcs7Sha1, PdfVersion::PDF16, true];
    }

    #[DataProvider('algorithmVersions')]
    public function testNormativeAlgorithmKeyAndDigestVersions(SignatureKeyAlgorithm $algorithm, int $bits, SignatureDigestMethod $digest, SignatureFormat $format, PdfVersion $version, bool $valid): void
    {
        $parameters = new SignatureAlgorithmParameters($algorithm, new IntegerType($bits), $digest);
        if (!$valid) {
            $this->expectException(\LogicException::class);
        }
        $parameters->validateVersion($this->effective($version), $format);
        self::assertSame($bits, $parameters->keyBits->integer());
    }

    /** @return iterable<string, array{SignatureKeyAlgorithm, int}> */
    public static function unsupportedKeys(): iterable
    {
        yield 'RSA4097' => [SignatureKeyAlgorithm::RSA, 4097];
        yield 'DSA4097' => [SignatureKeyAlgorithm::DSA, 4097];
        yield 'missing key bits' => [SignatureKeyAlgorithm::RSA, 0];
        yield 'negative key bits' => [SignatureKeyAlgorithm::RSA, -1];
        yield 'unknown algorithm' => [SignatureKeyAlgorithm::Other, 256];
    }

    #[DataProvider('unsupportedKeys')]
    public function testOnlyNormalizedPositiveKeySizesAreAccepted(SignatureKeyAlgorithm $algorithm, int $bits): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureAlgorithmParameters($algorithm, new IntegerType($bits), SignatureDigestMethod::SHA1);
    }

    public function testNativeBackendDeclaresTheActualKeyAndDistinctDigests(): void
    {
        $backend = new OpenSslSignatureBackend($this->identity());
        self::assertSame(2048, $backend->algorithmParameters(SignatureFormat::Pkcs7Detached)->keyBits->integer());
        self::assertSame(SignatureKeyAlgorithm::RSA, $backend->algorithmParameters(SignatureFormat::Pkcs7Detached)->keyAlgorithm);
        self::assertSame(SignatureDigestMethod::SHA256, $backend->algorithmParameters(SignatureFormat::Pkcs7Detached)->digest);
        self::assertSame(SignatureDigestMethod::SHA256, $backend->algorithmParameters(SignatureFormat::Pkcs7Sha1)->digest);
        self::assertSame(SignatureDigestMethod::SHA1, $backend->algorithmParameters(SignatureFormat::X509RsaSha1)->digest);
    }

    public function testActualRsa1024Pkcs1SignsPdf13AndVerifiesItsExactByteRange(): void
    {
        $identity = $this->identity('rsa-1024');
        $prepared = $this->prepared(SignatureFormat::X509RsaSha1, PdfVersion::PDF13, identity: 'rsa-1024');
        $signed = $prepared->sign(new OpenSslSignatureBackend($identity));
        // The1024-bit RSA value occupies128 bytes, preceded by DER OCTET STRING04 81 80.
        self::assertSame("\x04\x81\x80", substr($signed->value->bytes, 0, 3));
        self::assertSame(1, openssl_verify($prepared->coveredBytes(), substr($signed->value->bytes, 3), $identity->certificate, OPENSSL_ALGO_SHA1));
        self::assertSame(0, openssl_verify($prepared->coveredBytes() . 'tampered', substr($signed->value->bytes, 3), $identity->certificate, OPENSSL_ALGO_SHA1));
    }

    public function testActualRsa2048Pkcs1RejectsPdf14BeforeSigning(): void
    {
        $prepared = $this->prepared(SignatureFormat::X509RsaSha1, PdfVersion::PDF14);
        $original = $prepared->bytes;
        try {
            $prepared->sign(new OpenSslSignatureBackend($this->identity()));
            self::fail('RSA2048 was signed in PDF1.4.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('PDF1.5', str_replace(' ', '', $exception->getMessage()));
            self::assertSame($original, $prepared->bytes);
        }
    }

    public function testActualRsa2048Pkcs1AcceptsPdf15(): void
    {
        $prepared = $this->prepared(SignatureFormat::X509RsaSha1, PdfVersion::PDF15);
        self::assertNotEmpty($prepared->sign(new OpenSslSignatureBackend($this->identity()))->value->bytes);
    }

    /** @return iterable<string, array{SignatureFormat, ?PdfVersion, bool}> */
    public static function nativeCmsVersions(): iterable
    {
        foreach ([SignatureFormat::Pkcs7Detached, SignatureFormat::Pkcs7Sha1] as $format) {
            yield $format->name . ' no upgrade' => [$format, null, false];
            yield $format->name . ' catalog1.5' => [$format, PdfVersion::PDF15, false];
            yield $format->name . ' catalog1.6' => [$format, PdfVersion::PDF16, true];
        }
    }

    #[DataProvider('nativeCmsVersions')]
    public function testNativeCmsSha256UsesTheFrozenEffectiveCatalogVersion(SignatureFormat $format, ?PdfVersion $catalogVersion, bool $valid): void
    {
        $prepared = $this->prepared($format, PdfVersion::PDF14, $catalogVersion);
        if (!$valid) {
            $this->expectException(\LogicException::class);
        }
        $signed = $prepared->sign(new OpenSslSignatureBackend($this->identity()));
        self::assertStringStartsWith('%PDF-1.4', $signed->revision->bytes);
        self::assertStringContainsString('/Version /1.6', $signed->revision->bytes);
    }

    public function testActualDsaKeyRequiresPdf16ForCms(): void
    {
        $backend = new OpenSslSignatureBackend($this->identity('dsa-1024'));
        self::assertSame(SignatureKeyAlgorithm::DSA, $backend->algorithmParameters(SignatureFormat::Pkcs7Detached)->keyAlgorithm);
        $backend->validateVersion($this->effective(PdfVersion::PDF16), SignatureFormat::Pkcs7Detached);
        $this->expectException(\LogicException::class);
        $backend->validateVersion($this->effective(PdfVersion::PDF15), SignatureFormat::Pkcs7Detached);
    }

    public function testActualDsaKeyCannotBeSignedWithRsaOnlyFormat(): void
    {
        $backend = new OpenSslSignatureBackend($this->identity('dsa-1024'), 'file://must-never-be-accessed');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an RSA');
        $backend->sign('covered bytes', SignatureFormat::X509RsaSha1);
    }

    public function testActualDsaCmsBackendSignsAtPdf16(): void
    {
        $prepared = $this->prepared(SignatureFormat::Pkcs7Detached, PdfVersion::PDF16, identity: 'dsa-1024');
        $signed = $prepared->sign(new OpenSslSignatureBackend($this->identity('dsa-1024')));
        self::assertStringStartsWith("\x30", $signed->value->bytes);
        self::assertSame($prepared->coveredBytes(), $signed->range->coveredBytes($signed->revision->bytes));
    }

    public function testActualEcKeyIsRejectedBeforeNativeCmsAndTemporaryFiles(): void
    {
        $backend = new OpenSslSignatureBackend($this->identity('ec-p256'), 'file://must-never-be-accessed');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only RSA and DSA');
        $backend->sign('covered bytes', SignatureFormat::Pkcs7Detached);
    }

    public function testActualRsa4098KeyIsRejectedBeforeNativeCmsAndTemporaryFiles(): void
    {
        $identity = $this->identity('rsa-4098');
        self::assertSame(4098, openssl_pkey_get_details($identity->key())['bits']);
        $backend = new OpenSslSignatureBackend($identity, 'file://must-never-be-accessed');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('4096');
        $backend->sign('covered bytes', SignatureFormat::Pkcs7Detached);
    }

    public function testVersionedExternalBackendIsInvokedBeforeConsumingPreparedBytes(): void
    {
        $backend = new class () implements VersionedSignatureBackend {
            public bool $signed = false;
            public function validateVersion(EffectivePdfVersion $version, SignatureFormat $format): void
            {
                TestCase::assertSame(PdfVersion::PDF15, $version->value);
                TestCase::assertSame(SignatureFormat::Pkcs7Detached, $format);
                $version->require(PdfVersion::PDF17, 'External handler requirement');
            }
            public function sign(string $coveredBytes, SignatureFormat $format): SignatureValue
            {
                $this->signed = true;
                return new SignatureValue('unused');
            }
        };
        $prepared = $this->prepared(SignatureFormat::Pkcs7Detached, PdfVersion::PDF15);
        try {
            $prepared->sign($backend);
            self::fail('The versioned backend requirement was ignored.');
        } catch (\LogicException) {
            self::assertFalse($backend->signed);
        }
    }
}
