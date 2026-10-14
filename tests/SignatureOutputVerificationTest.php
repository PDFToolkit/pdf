<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureVerificationInput;
use PDF\Document\Signatures\SignatureValue;
use PDF\Document\Signatures\Policies\CertificateSeedFlag;
use PDF\Document\Signatures\Policies\CertificateSeedFlags;
use PDF\Document\Signatures\Policies\CertificateSubjectName;
use PDF\Document\Signatures\Policies\SignatureCertificateSeedValue;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\OpenSslSignatureCertificateFacts;
use PDF\Security\OpenSslSignatureOutputVerifier;
use PDF\Security\SignatureCmsInspection;
use PDF\Security\SignatureDerReader;
use PDF\Security\X509Certificate;
use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureOutputVerificationTest extends TestCase
{
    private function identity(string $name = 'signer'): OpenSslIdentity
    {
        $certificate = file_get_contents(__DIR__ . '/Fixtures/signing/' . $name . '.der');
        $key = file_get_contents(__DIR__ . '/Fixtures/signing/' . $name . '-private-key.pem');
        return new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString($certificate))), $key);
    }

    /** @return iterable<string, array{SignatureFormat, string}> */
    public static function formats(): iterable
    {
        foreach ([SignatureFormat::Pkcs7Detached, SignatureFormat::Pkcs7Sha1, SignatureFormat::X509RsaSha1] as $format) {
            yield $format->name => [$format, 'signer'];
        }
        yield 'DSA detached' => [SignatureFormat::Pkcs7Detached, 'dsa-1024'];
        yield 'DSA sha1 wrapper' => [SignatureFormat::Pkcs7Sha1, 'dsa-1024'];
    }

    #[DataProvider('formats')]
    public function testNativeVerificationBindsTheActualBytesEncodingAndCredential(SignatureFormat $format, string $name): void
    {
        $backend = new OpenSslSignatureBackend($this->identity($name));
        $bytes = "actual PDF byte ranges\0with binary content";
        $value = $backend->sign($bytes, $format);
        $input = new SignatureVerificationInput($bytes, $value, $format, $backend->certificateChain());
        $facts = (new OpenSslSignatureOutputVerifier())->verify($input);
        $facts->validateBinding($input);
        self::assertSame($format === SignatureFormat::X509RsaSha1 ? SignatureDigestMethod::SHA1 : SignatureDigestMethod::SHA256, $facts->algorithms->digest);
        self::assertSame($backend->certificateChain()->signer->value->value, $facts->certificate->certificate()->value);
        $this->expectException(\InvalidArgumentException::class);
        $facts->validateBinding(new SignatureVerificationInput($bytes . 'tampered', $value, $format, $backend->certificateChain()));
    }

    #[DataProvider('formats')]
    public function testAChangedActualByteRangeCannotBeVerified(SignatureFormat $format, string $name): void
    {
        $backend = new OpenSslSignatureBackend($this->identity($name));
        $value = $backend->sign('original range', $format);
        $this->expectException(\InvalidArgumentException::class);
        (new OpenSslSignatureOutputVerifier())->verify(new SignatureVerificationInput('changed range', $value, $format, $backend->certificateChain()));
    }

    #[DataProvider('formats')]
    public function testAnotherCertificateCannotProvideVerifiedFacts(SignatureFormat $format, string $name): void
    {
        $backend = new OpenSslSignatureBackend($this->identity($name));
        $value = $backend->sign('original range', $format);
        $this->expectException(\InvalidArgumentException::class);
        (new OpenSslSignatureOutputVerifier())->verify(new SignatureVerificationInput('original range', $value, $format, $this->identity('recipient')->chain));
    }

    public function testCmsDigestMustBeReadFromTheSignerInfoAndVerified(): void
    {
        $backend = new OpenSslSignatureBackend($this->identity());
        $value = $backend->sign('covered bytes', SignatureFormat::Pkcs7Detached);
        $inspection = new SignatureCmsInspection($value->bytes, SignatureFormat::Pkcs7Detached);
        self::assertSame(SignatureDigestMethod::SHA256, $inspection->digest);
        $changed = str_replace("\x60\x86\x48\x01\x65\x03\x04\x02\x01", "\x60\x86\x48\x01\x65\x03\x04\x02\x02", $value->bytes);
        self::assertNotSame($value->bytes, $changed);
        $this->expectException(\InvalidArgumentException::class);
        (new OpenSslSignatureOutputVerifier())->verify(new SignatureVerificationInput('covered bytes', new SignatureValue($changed), SignatureFormat::Pkcs7Detached, $backend->certificateChain()));
    }

    public function testAttachedAndDetachedEncodingsCannotBeConfused(): void
    {
        $backend = new OpenSslSignatureBackend($this->identity());
        $value = $backend->sign('covered bytes', SignatureFormat::Pkcs7Sha1);
        $this->expectException(\InvalidArgumentException::class);
        (new OpenSslSignatureOutputVerifier())->verify(new SignatureVerificationInput('covered bytes', $value, SignatureFormat::Pkcs7Detached, $backend->certificateChain()));
    }

    public function testCompletedCmsOutputCannotBeReusedWithDifferentVerifiedFacts(): void
    {
        $backend = new OpenSslSignatureBackend($this->identity());
        $one = $backend->sign('one', SignatureFormat::Pkcs7Detached);
        $two = $backend->sign('two', SignatureFormat::Pkcs7Detached);
        $input = new SignatureVerificationInput('one', $one, SignatureFormat::Pkcs7Detached, $backend->certificateChain());
        $facts = $backend->outputVerifier()->verify($input);
        $this->expectException(\InvalidArgumentException::class);
        $facts->validateBinding(new SignatureVerificationInput('one', $two, SignatureFormat::Pkcs7Detached, $backend->certificateChain()));
    }

    public function testFactsUseTheActualParsedSubjectUsageAndVerifiedSelfIssuer(): void
    {
        $facts = new OpenSslSignatureCertificateFacts($this->identity()->chain);
        $subject = new CertificateSubjectName(new Entry(new NameType('2.5.4.3'), new TextString('PDFToolkit signer public test fixture')));
        self::assertTrue($facts->containsSubject($subject));
        self::assertSame($facts->certificate()->value, $facts->issuers()[0]->value);
        $policy = new SignatureCertificateSeedValue(
            new CertificateSeedFlags(CertificateSeedFlag::Subject, CertificateSeedFlag::Issuer, CertificateSeedFlag::SubjectDN, CertificateSeedFlag::KeyUsage),
            new Entry(new NameType('Subject'), new ArrayType($facts->certificate())),
            new Entry(new NameType('Issuer'), new ArrayType($facts->certificate())),
            new Entry(new NameType('SubjectDN'), new ArrayType($subject)),
            new Entry(new NameType('KeyUsage'), new ArrayType(new AsciiString('111000000')))
        );
        $policy->validateFacts($facts);
        self::assertTrue($policy->requiresValidation());
    }

    public function testUnrelatedCertificateCannotBecomeAnIssuerFact(): void
    {
        $identity = $this->identity();
        $unrelated = $this->identity('recipient');
        $this->expectException(\InvalidArgumentException::class);
        new OpenSslSignatureCertificateFacts(new CertificateChain($identity->chain->signer, $unrelated->chain->signer));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDer(): iterable
    {
        foreach (['', "\x04", "\x04\x80\0\0", "\x04\x81\x01x", "\x04\x82\0\x80", "\x04\x7f", "\x1f\0", "\0\0", "\x04\xff", "\x04\x01x\x04\0"] as $index => $bytes) {
            yield (string) $index => [$bytes];
        }
    }

    #[DataProvider('invalidDer')]
    public function testNativeDerFramingRejectsTruncationIndefiniteNonminimalOrMultipleObjects(string $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SignatureDerReader())->element($bytes);
    }
}
