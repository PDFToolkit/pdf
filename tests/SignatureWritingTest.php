<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\FileStructure;
use PDF\Syntax\DataStructure\TextString;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureValue;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureWritingTest extends TestCase
{
    /** @return iterable<string, array{CrossReferenceFormat, SignatureFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $xref) {
            foreach ([SignatureFormat::Pkcs7Detached, SignatureFormat::Pkcs7Sha1, SignatureFormat::X509RsaSha1] as $signature) {
                yield $xref->name . ' ' . $signature->name => [$xref, $signature];
            }
        }
    }

    private function identity(): OpenSslIdentity
    {
        $certificate = file_get_contents(__DIR__ . '/Fixtures/signing/signer.der');
        $key = file_get_contents(__DIR__ . '/Fixtures/signing/signer-private-key.pem');
        return new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString($certificate))), $key);
    }

    private function signature(SignatureFormat $format, int $capacity = 8192): PendingSignature
    {
        $cert = $format === SignatureFormat::X509RsaSha1 ? [new Entry(new NameType('Cert'), $this->identity()->chain->object())] : [];
        return new PendingSignature(
            new SignatureCapacity($capacity),
            $format,
            new NameType('Adobe.PPKLite'),
            ...[new Entry(new NameType('Reason'), new LiteralString('Reviewed')), ...$cert]
        );
    }

    private function file(PendingSignature $signature, CrossReferenceFormat $xref): FileStructure
    {
        $fields = (new FieldTree())->addRoot(new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($signature)));
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('AcroForm'), new IndirectReference(new AcroForm(
            $fields,
            entries: new Entry(new NameType('SigFlags'), new IntegerType(3))
        ))));
        return new FileStructure(new FileHeader(), new FileBody($catalog), crossReferenceFormat: $xref);
    }

    #[DataProvider('formats')]
    public function testReservationsFixByteRangesBeforeAllThreeNativeSignatureFormats(CrossReferenceFormat $xref, SignatureFormat $format): void
    {
        $pending = $this->signature($format);
        $prepared = (new SignatureWriter())->prepare($this->file($pending, $xref), $pending);
        $signed = $prepared->sign(new OpenSslSignatureBackend($this->identity()));
        self::assertSame(strlen($prepared->revision->bytes), strlen($signed->revision->bytes));
        self::assertSame($prepared->coveredBytes(), $signed->range->coveredBytes($signed->revision->bytes));
        self::assertSame($prepared->revision->crossReferenceOffset->value, $signed->revision->crossReferenceOffset->value);
        self::assertNotSame($prepared->revision->bytes, $signed->revision->bytes);
        self::assertStringContainsString('/SubFilter /' . $format->value, $signed->revision->bytes);
        self::assertSame($prepared->range->object()->items[2]->value, $signed->range->afterOffset);
        $appended = (new IncrementalUpdate($signed->revision, new FileBody(new LiteralString('after signing'))))->toRevision();
        self::assertStringStartsWith($signed->revision->bytes, $appended->bytes);
        self::assertSame($prepared->coveredBytes(), $signed->range->coveredBytes($appended->bytes));
    }

    public function testSignatureCapacityOverflowLeavesThePreparedRevisionUnchanged(): void
    {
        $pending = $this->signature(SignatureFormat::Pkcs7Detached, 4);
        $prepared = (new SignatureWriter())->prepare($this->file($pending, CrossReferenceFormat::Table), $pending);
        $bytes = $prepared->bytes;
        try {
            $prepared->finalize(new SignatureValue('too large'));
            self::fail('An oversized signature was accepted.');
        } catch (\LengthException) {
            self::assertSame($bytes, $prepared->bytes);
            self::assertStringContainsString('/Contents <00000000>', $prepared->revision->bytes);
        }
    }

    public function testHexadecimalSignatureSlotCannotBeConfusedWithAnUnrelatedIdenticalString(): void
    {
        $pending = $this->signature(SignatureFormat::Pkcs7Detached, 4);
        $file = $this->file($pending, CrossReferenceFormat::Table);
        $file = new FileStructure($file->header, new FileBody(new HexadecimalString(str_repeat("\0", 4)), ...$file->body->objects()));
        $prepared = (new SignatureWriter())->prepare($file, $pending);
        $signed = $prepared->finalize(new SignatureValue('abcd'));
        self::assertSame(1, substr_count($signed->revision->bytes, '<00000000>'));
        self::assertStringContainsString('/Contents <61626364>', $signed->revision->bytes);
    }

    public function testPkcs7CannotSmuggleASeparateCertEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PendingSignature(
            new SignatureCapacity(8),
            SignatureFormat::Pkcs7Detached,
            entries: new Entry(new NameType('Cert'), new LiteralString('certificate'))
        );
    }

    public function testBackendCredentialsRejectFilenameInputsBeforeReadingAnyFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpenSslIdentity($this->identity()->chain, 'file://unexpected-private-key.pem');
    }

    public function testFinalizationCannotIgnoreMandatorySeedValuesFromARawDictionary(): void
    {
        $pending = $this->signature(SignatureFormat::Pkcs7Detached, 4);
        $seed = new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(8)));
        $field = new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending), seedValue: new IndirectReference($seed));
        $form = new AcroForm((new FieldTree())->addRoot($field));
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('AcroForm'), new IndirectReference($form)));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $pending);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('seed obligations');
        $prepared->finalize(new SignatureValue('abcd'));
    }

    public function testAnAdvisoryOnlySeedDoesNotInventAMandatoryConstraint(): void
    {
        $pending = $this->signature(SignatureFormat::Pkcs7Detached, 4);
        $seed = new DictionaryType(new Entry(new NameType('Filter'), new NameType('AReaderPreference')));
        $field = new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending), seedValue: new IndirectReference($seed));
        $form = new AcroForm((new FieldTree())->addRoot($field));
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('AcroForm'), new IndirectReference($form)));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $pending);
        self::assertSame('abcd', $prepared->finalize(new SignatureValue('abcd'))->value->bytes);
    }

    public function testNativePkcs1SignerRejectsAnotherDeclaredCertificate(): void
    {
        $other = file_get_contents(__DIR__ . '/Fixtures/signing/recipient.der');
        $pending = new PendingSignature(
            new SignatureCapacity(8192),
            SignatureFormat::X509RsaSha1,
            entries: new Entry(new NameType('Cert'), new HexadecimalString($other))
        );
        $prepared = (new SignatureWriter())->prepare($this->file($pending, CrossReferenceFormat::Table), $pending);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actual signing key');
        $prepared->sign(new OpenSslSignatureBackend($this->identity()));
    }
}
