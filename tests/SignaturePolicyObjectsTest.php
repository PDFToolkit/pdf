<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureValue;
use PDF\Document\Signatures\Policies\CertificateKeyUsage;
use PDF\Document\Signatures\Policies\CertificateKeyUsagePattern;
use PDF\Document\Signatures\Policies\CertificatePolicyOid;
use PDF\Document\Signatures\Policies\CertificateSeedFlag;
use PDF\Document\Signatures\Policies\CertificateSeedFlags;
use PDF\Document\Signatures\Policies\CertificateSubjectName;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\Policies\DocMdpTransform;
use PDF\Document\Signatures\Policies\FieldMdpTransform;
use PDF\Document\Signatures\Policies\SignatureCertificateFacts;
use PDF\Document\Signatures\Policies\SignatureCertificateSeedValue;
use PDF\Document\Signatures\Policies\SignatureCertificateUrlUsage;
use PDF\Document\Signatures\Policies\SignatureDataReference;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureDocumentLock;
use PDF\Document\Signatures\Policies\SignatureFieldNames;
use PDF\Document\Signatures\Policies\SignatureHandlerCapabilities;
use PDF\Document\Signatures\Policies\SignatureKeyAlgorithm;
use PDF\Document\Signatures\Policies\SignatureReference;
use PDF\Document\Signatures\Policies\SignatureReferences;
use PDF\Document\Signatures\Policies\SignatureSeedFlag;
use PDF\Document\Signatures\Policies\SignatureSeedFlags;
use PDF\Document\Signatures\Policies\SignatureSeedMdp;
use PDF\Document\Signatures\Policies\SignatureSeedValidationRequirement;
use PDF\Document\Signatures\Policies\SignatureSeedValue;
use PDF\Document\Signatures\Policies\SignatureSigningChoice;
use PDF\Document\Signatures\Policies\SignatureSigningEvidence;
use PDF\Document\Signatures\Policies\SignatureTimeStampSeed;
use PDF\Document\Signatures\Policies\SignatureTransformMethod;
use PDF\Document\Signatures\Policies\UnavailableSignatureEvidence;
use PDF\Document\Signatures\Policies\UsageRight;
use PDF\Document\Signatures\Policies\UsageRightsMode;
use PDF\Document\Signatures\Policies\UsageRightsTransform;
use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\DataStructure\ByteString;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\RealType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignaturePolicyObjectsTest extends TestCase
{
    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function capabilities(SignatureFormat ...$formats): SignatureHandlerCapabilities
    {
        return new SignatureHandlerCapabilities(new NameType('Adobe.PPKLite'), new NumericType(3), ...($formats === [] ? [SignatureFormat::Pkcs7Detached] : $formats));
    }

    private function choice(SignatureDigestMethod $digest = SignatureDigestMethod::SHA256, ?TextString $reason = null): SignatureSigningChoice
    {
        return new SignatureSigningChoice($this->capabilities(), SignatureFormat::Pkcs7Detached, $digest, $reason);
    }

    private function facts(SignatureKeyAlgorithm $algorithm = SignatureKeyAlgorithm::RSA, ?CertificateKeyUsage $usage = new CertificateKeyUsage(new IntegerType(7))): SignaturePolicyCertificateFactsFixture
    {
        $der = file_get_contents(__DIR__ . '/Fixtures/signing/signer.der');
        self::assertIsString($der);
        return new SignaturePolicyCertificateFactsFixture(new ByteString($der), $algorithm, $usage);
    }

    private function fieldTransform(SignatureLockAction $action = SignatureLockAction::Include): FieldMdpTransform
    {
        return new FieldMdpTransform(new SignatureFieldLock($action, $action === SignatureLockAction::All ? null : new ArrayType(new TextString('order')), new IntegerType(2)));
    }

    public function testDocMdpAndFieldMdpUseExactNameVersionsAndCopyOnlyLockSelection(): void
    {
        $doc = new DocMdpTransform();
        self::assertSame('/1.2', $doc->get('V')->toPdf(new SerializationContext()));
        self::assertSame(2, $doc->get('P')->value);
        $field = $this->fieldTransform();
        self::assertSame('Include', $field->get('Action')->value);
        self::assertFalse($field->has('P'));
        self::assertTrue($field->locks('order'));
        self::assertTrue($field->locks('order.total'));
        self::assertFalse($field->locks('orders'));
        self::assertFalse($this->fieldTransform(SignatureLockAction::Exclude)->locks('order.total'));
        self::assertTrue($this->fieldTransform(SignatureLockAction::Exclude)->locks('customer'));
        self::assertTrue($this->fieldTransform(SignatureLockAction::All)->locks('anything'));
    }

    public function testDuplicateQualifiedNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureFieldNames(new TextString('a'), new TextString('a'));
    }

    public function testUnboundDataCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        (new SignatureReference($this->fieldTransform(), new SignatureDataReference()))->toPdf(new SerializationContext());
    }

    public function testDataBinderIsIdempotentAndCannotChangeDocument(): void
    {
        $reference = new IndirectReference(new DictionaryType());
        $binding = new SignatureDataReference($reference);
        self::assertSame($binding, $binding->bind($reference));
        self::assertSame($reference, $binding->reference());
        $this->expectException(\LogicException::class);
        $binding->bind(new IndirectReference(new DictionaryType()));
    }

    public function testDataBinderRejectsNonDictionary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureDataReference(new IndirectReference(new IntegerType(1)));
    }

    public function testActualWriterAcceptsCircularCatalogBindingAndDirectTransformParameters(): void
    {
        $binding = new SignatureDataReference();
        $references = new SignatureReferences(new SignatureReference(new DocMdpTransform()), new SignatureReference($this->fieldTransform(), $binding));
        $pending = new PendingSignature(new SignatureCapacity(32), SignatureFormat::Pkcs7Detached, entries: $this->entry('Reference', $references));
        $fields = (new FieldTree())->addRoot(new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending)));
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm($fields))));
        $binding->bind(new IndirectReference($catalog));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $pending);
        self::assertStringContainsString('/TransformMethod /FieldMDP /TransformParams <<', $prepared->bytes);
        self::assertStringContainsString('/Data 1 0 R', $prepared->bytes);
        self::assertStringContainsString('/DigestMethod /SHA1', $prepared->bytes);
        self::assertSame($references->references[1], $references->reference(SignatureTransformMethod::FieldMDP));
    }

    public function testActualWriterRejectsDataFromAnotherCatalog(): void
    {
        $reference = new SignatureReference($this->fieldTransform(), new IndirectReference(new DocumentCatalog(new PageTreeNode())));
        $pending = new PendingSignature(new SignatureCapacity(32), SignatureFormat::Pkcs7Detached, entries: $this->entry('Reference', new SignatureReferences($reference)));
        $fields = (new FieldTree())->addRoot(new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending)));
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm($fields))));
        $this->expectException(\InvalidArgumentException::class);
        (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $pending);
    }

    public function testDataIsRequiredOnlyForFieldMdp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureReference($this->fieldTransform());
    }

    public function testDuplicateTransformsCannotHideDifferentPermissions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureReferences(new SignatureReference(new DocMdpTransform(DocMdpPermissions::NoChanges)), new SignatureReference(new DocMdpTransform()));
    }

    public function testUsageRightsCannotBeCombinedWithCertification(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureReferences(new SignatureReference(new DocMdpTransform()), new SignatureReference(new UsageRightsTransform(UsageRightsMode::ByteRange)));
    }

    /** @return iterable<string, array{UsageRightsMode, bool, UsageRight, bool, bool}> */
    public static function savingRules(): iterable
    {
        yield 'legacy fill saves' => [UsageRightsMode::Legacy, false, UsageRight::FillField, false, true];
        yield 'UR3 fill alone does not save' => [UsageRightsMode::ByteRange, false, UsageRight::FillField, false, false];
        yield 'UR3 restrictive fill saves' => [UsageRightsMode::ByteRange, true, UsageRight::FillField, false, true];
        yield 'annotation modification saves' => [UsageRightsMode::ByteRange, false, UsageRight::CreateAnnotation, false, true];
        yield 'annotation copy does not save' => [UsageRightsMode::ByteRange, false, UsageRight::CopyAnnotation, false, false];
        yield 'signature saves only for signing' => [UsageRightsMode::ByteRange, false, UsageRight::ModifySignature, false, false];
        yield 'signature operation saves' => [UsageRightsMode::ByteRange, false, UsageRight::ModifySignature, true, true];
        yield 'fullsave grants saving' => [UsageRightsMode::ByteRange, false, UsageRight::FullSave, false, true];
        yield 'export does not save' => [UsageRightsMode::Legacy, true, UsageRight::ExportForm, false, false];
    }

    #[DataProvider('savingRules')]
    public function testUsageRightsNativeSavingConditions(UsageRightsMode $mode, bool $restrict, UsageRight $right, bool $signing, bool $expected): void
    {
        $rights = new UsageRightsTransform($mode, $restrict, null, $right);
        self::assertSame($expected, $rights->allowsSaving($signing));
        self::assertTrue($rights->allows($right));
        self::assertSame($right->name(), $rights->get($right->key())->items[0]->value);
    }

    public function testModernUsageRightRequiresUr3(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UsageRightsTransform(UsageRightsMode::Legacy, rights: UsageRight::BarcodePlaintext);
    }

    public function testUsageRightsVersionGateReflectsEmbeddedFiles(): void
    {
        $rights = new UsageRightsTransform(UsageRightsMode::Legacy, rights: UsageRight::CreateEmbeddedFile);
        $this->expectException(\LogicException::class);
        $rights->validateVersion(new EffectivePdfVersion(new FileHeader(PdfVersion::PDF15), new DictionaryType()));
    }

    public function testOptionalSubFilterStillChoosesFirstSupportedPreference(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('SubFilter', new ArrayType(new NameType('private.unknown'), new NameType('adbe.pkcs7.sha1'), new NameType('adbe.pkcs7.detached'))));
        $capabilities = $this->capabilities(SignatureFormat::Pkcs7Detached, SignatureFormat::Pkcs7Sha1);
        self::assertSame(SignatureFormat::Pkcs7Sha1, $seed->selectFormat($capabilities));
        self::assertTrue($seed->requiresValidation());
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateChoice(new SignatureSigningChoice($capabilities, SignatureFormat::Pkcs7Detached, SignatureDigestMethod::SHA256), $this->facts());
    }

    public function testOptionalUnknownSubFilterFallsBackToHandler(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('SubFilter', new ArrayType(new NameType('unknown'))));
        self::assertSame(SignatureFormat::Pkcs7Detached, $seed->selectFormat($this->capabilities()));
    }

    public function testMandatoryUnknownSubFilterCannotFallBack(): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::SubFilter), $this->entry('SubFilter', new ArrayType(new NameType('unknown'))));
        $this->expectException(\InvalidArgumentException::class);
        $seed->selectFormat($this->capabilities());
    }

    public function testHandlerCapabilitiesRequireAnExplicitName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureHandlerCapabilities(new NameType(''), new NumericType(3), SignatureFormat::Pkcs7Detached);
    }

    public function testHandlerCapabilitiesDoNotRepeatFormats(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->capabilities(SignatureFormat::Pkcs7Detached, SignatureFormat::Pkcs7Detached);
    }

    public function testMandatoryHandlerAndParserCapabilityAreEnforced(): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Filter, SignatureSeedFlag::ParserCapability), $this->entry('Filter', new NameType('private.handler')), $this->entry('V', new NumericType(3)));
        self::assertSame('3.0', $seed->get('V')->toPdf(new SerializationContext()));
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateChoice($this->choice(), $this->facts());
    }

    public function testMatchingHandlerCannotBypassParserVersion(): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::ParserCapability), $this->entry('V', new NumericType(3)));
        $capabilities = new SignatureHandlerCapabilities(new NameType('Adobe.PPKLite'), new NumericType(2), SignatureFormat::Pkcs7Detached);
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateChoice(new SignatureSigningChoice($capabilities, SignatureFormat::Pkcs7Detached, SignatureDigestMethod::SHA256), $this->facts());
    }

    /** @return iterable<string, array{array<Entry>, ?TextString, bool}> */
    public static function requiredReasons(): iterable
    {
        yield 'absent forbids reason' => [[], new TextString('custom'), false];
        yield 'absent allows omission' => [[], null, true];
        yield 'period forbids reason' => [[new Entry(new NameType('Reasons'), new ArrayType(new TextString('.')))], new TextString('custom'), false];
        yield 'period allows omission' => [[new Entry(new NameType('Reasons'), new ArrayType(new TextString('.')))], null, true];
        yield 'empty array prohibits signing' => [[new Entry(new NameType('Reasons'), new ArrayType())], null, false];
        yield 'exact listed reason accepted' => [[new Entry(new NameType('Reasons'), new ArrayType(new TextString('Approved')))], new TextString('Approved'), true];
        yield 'different case rejected' => [[new Entry(new NameType('Reasons'), new ArrayType(new TextString('Approved')))], new TextString('approved'), false];
        yield 'empty string remains exact value' => [[new Entry(new NameType('Reasons'), new ArrayType(new TextString('')))], new TextString(''), true];
    }

    #[DataProvider('requiredReasons')]
    public function testExactIsoRequiredReasonRules(array $entries, ?TextString $reason, bool $valid): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Reasons), ...$entries);
        if (!$valid) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $seed->validateChoice($this->choice(reason: $reason), $this->facts());
        self::assertTrue($seed->requiresValidation());
    }

    public function testOptionalReasonDoesNotRestrictApplication(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('Reasons', new ArrayType(new TextString('preferred'))));
        $seed->validateChoice($this->choice(reason: new TextString('other')), $this->facts());
        self::assertFalse($seed->requiresValidation());
    }

    public function testCertificationSeedZeroRequiresApprovalAndPositiveRequiresExactP(): void
    {
        $approval = new SignatureSeedValue(entries: $this->entry('MDP', new SignatureSeedMdp(new IntegerType(0))));
        $approval->validateChoice($this->choice(), $this->facts());
        self::assertTrue($approval->requiresValidation());
        $certification = new SignatureSeedValue(entries: $this->entry('MDP', new SignatureSeedMdp(new IntegerType(2))));
        $certification->validateChoice(new SignatureSigningChoice($this->capabilities(), SignatureFormat::Pkcs7Detached, SignatureDigestMethod::SHA256, certification: DocMdpPermissions::FormFillingTemplatesAndSigning), $this->facts());
        $this->expectException(\InvalidArgumentException::class);
        $certification->validateChoice($this->choice(), $this->facts());
    }

    public function testRequiredAppearanceLockAndLegalAttestationMatchExactChoices(): void
    {
        $seed = new SignatureSeedValue(
            new SignatureSeedFlags(SignatureSeedFlag::LegalAttestation, SignatureSeedFlag::LockDocument, SignatureSeedFlag::AppearanceFilter),
            $this->entry('LegalAttestation', new ArrayType(new TextString('Reviewed document'))),
            $this->entry('LockDocument', new NameType('true')),
            $this->entry('AppearanceFilter', new TextString('Handwritten'))
        );
        $choice = new SignatureSigningChoice(
            $this->capabilities(),
            SignatureFormat::Pkcs7Detached,
            SignatureDigestMethod::SHA256,
            legalAttestation: new TextString('Reviewed document'),
            documentLock: SignatureDocumentLock::Lock,
            appearance: new TextString('Handwritten')
        );
        $seed->validateChoice($choice, $this->facts());
        self::assertTrue($seed->requiresValidation());
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateChoice($this->choice(), $this->facts());
    }

    public function testAutomaticLockIsAdvisoryEvenWithRequiredBit(): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::LockDocument), $this->entry('LockDocument', new NameType('auto')));
        $seed->validateChoice($this->choice(), $this->facts());
        self::assertFalse($seed->requiresValidation());
    }

    public function testRequiredDigestConstrainsRsaButDsaUsesSha1(): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::DigestMethod), $this->entry('DigestMethod', new ArrayType(new NameType('SHA256'))));
        $seed->validateChoice($this->choice(), $this->facts());
        $seed->validateChoice($this->choice(SignatureDigestMethod::SHA1), $this->facts(SignatureKeyAlgorithm::DSA));
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateChoice($this->choice(SignatureDigestMethod::SHA1), $this->facts());
    }

    public function testDsaDoesNotAcceptNonSha1Digest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SignatureSeedValue())->validateChoice($this->choice(), $this->facts(SignatureKeyAlgorithm::DSA));
    }

    public function testPkcs1FormatPermitsSha256PerTable257ButRequiresRsa(): void
    {
        $capabilities = $this->capabilities(SignatureFormat::X509RsaSha1);
        (new SignatureSeedValue())->validateChoice(new SignatureSigningChoice($capabilities, SignatureFormat::X509RsaSha1, SignatureDigestMethod::SHA256), $this->facts());
        self::assertSame(SignatureFormat::X509RsaSha1, $capabilities->formats[0]);
        $this->expectException(\InvalidArgumentException::class);
        (new SignatureSeedValue())->validateChoice(new SignatureSigningChoice($capabilities, SignatureFormat::X509RsaSha1, SignatureDigestMethod::SHA1), $this->facts(SignatureKeyAlgorithm::DSA));
    }

    public function testActualCertificateFactsMatchDerAndParsedSubject(): void
    {
        $facts = $this->facts();
        $cert = new SignatureCertificateSeedValue(
            new CertificateSeedFlags(CertificateSeedFlag::Subject, CertificateSeedFlag::Issuer, CertificateSeedFlag::SubjectDN, CertificateSeedFlag::KeyUsage),
            $this->entry('Subject', new ArrayType($facts->certificate())),
            $this->entry('Issuer', new ArrayType($facts->certificate())),
            $this->entry('SubjectDN', new ArrayType(new CertificateSubjectName($this->entry('CN', new TextString('PDFToolkit signer public test fixture'))))),
            $this->entry('KeyUsage', new ArrayType(new AsciiString('111000000')))
        );
        $cert->validateFacts($facts);
        self::assertTrue($cert->requiresValidation());
        self::assertSame(SignatureKeyAlgorithm::RSA, $facts->keyAlgorithm());
    }

    public function testCertificateSubjectCannotUseAnotherPublicFixture(): void
    {
        $der = file_get_contents(__DIR__ . '/Fixtures/signing/recipient.der');
        $seed = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::Subject), $this->entry('Subject', new ArrayType(new ByteString($der))));
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateFacts($this->facts());
    }

    public function testRequiredEmptySubjectCandidatesProhibitSigning(): void
    {
        $seed = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::Subject), $this->entry('Subject', new ArrayType()));
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateFacts($this->facts());
    }

    public function testAllSubjectDnDictionariesConstrainTheActualSubject(): void
    {
        $seed = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::SubjectDN), $this->entry('SubjectDN', new ArrayType(
            new CertificateSubjectName($this->entry('CN', new TextString('PDFToolkit signer public test fixture'))),
            new CertificateSubjectName($this->entry('O', new TextString('unavailable')))
        )));
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateFacts($this->facts());
    }

    public function testPolicyOidsNeedIssuerAndEveryRequiredOid(): void
    {
        $facts = $this->facts();
        $seed = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::PolicyOid), $this->entry('Issuer', new ArrayType($facts->certificate())), $this->entry('OID', new ArrayType(new CertificatePolicyOid('1.2.3'))));
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateFacts($facts);
    }

    public function testPolicyOidsWithoutIssuerAreInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureCertificateSeedValue(entries: $this->entry('OID', new ArrayType(new CertificatePolicyOid('1.2'))));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOids(): iterable
    {
        foreach (['1', '3.2', '1.40', '0.040', '2.01', '1.2.', '1.2.-1', '1.2.03', '1.2x'] as $oid) {
            yield $oid => [$oid];
        }
    }

    #[DataProvider('invalidOids')]
    public function testPolicyOidsRequireCanonicalDecimalArcs(string $oid): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CertificatePolicyOid($oid);
    }

    public function testPolicyOidAllowsArbitrarilyLargeArcsForTwo(): void
    {
        self::assertSame('2.9999999999999999999999.0', (new CertificatePolicyOid('2.9999999999999999999999.0'))->value);
        self::assertSame('1.2', (new CertificatePolicyOid('1.2'))->value);
    }

    public function testUsagePatternUnknownMissingAndExtraCharactersAreWildcards(): void
    {
        $usage = new CertificateKeyUsage(new IntegerType(7));
        self::assertTrue((new CertificateKeyUsagePattern(new AsciiString('1')))->matches($usage));
        self::assertTrue((new CertificateKeyUsagePattern(new AsciiString('1?X000000extra')))->matches($usage));
        self::assertFalse((new CertificateKeyUsagePattern(new AsciiString('10')))->matches($usage));
        self::assertFalse((new CertificateKeyUsagePattern(new AsciiString('XXX1')))->matches($usage));
    }

    public function testMultipleKeyUsagePatternsAreAlternatives(): void
    {
        $seed = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::KeyUsage), $this->entry('KeyUsage', new ArrayType(new AsciiString('0'), new AsciiString('1'))));
        $seed->validateFacts($this->facts());
        self::assertTrue($seed->requiresValidation());
    }

    public function testWildcardPatternStillRequiresPresentKeyUsageExtension(): void
    {
        $seed = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::KeyUsage), $this->entry('KeyUsage', new ArrayType(new AsciiString('XXXXXXXXX'))));
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateFacts($this->facts(usage: null));
    }

    public function testBrowserCertificateUrlFlagIsIgnoredWithoutNetwork(): void
    {
        $seed = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::URL), $this->entry('URL', new AsciiString('https://example.invalid/certificate')));
        $seed->validateFacts($this->facts());
        self::assertFalse($seed->requiresValidation());
    }

    public function testPrivateCertificateUrlRequiresItsActualExternalHandler(): void
    {
        $seed = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::URL), $this->entry('URL', new AsciiString('urn:provider:credential')), $this->entry('URLType', new NameType('PrivateProvider')));
        self::assertTrue($seed->requiresValidation());
        $this->expectException(\LogicException::class);
        $seed->validateFacts($this->facts());
    }

    public function testPrivateCertificateUrlHandlerFailureCannotBeIgnored(): void
    {
        $seed = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::URL), $this->entry('URL', new AsciiString('urn:provider:credential')), $this->entry('URLType', new NameType('PrivateProvider')));
        $adapter = new class () implements SignatureCertificateUrlUsage {
            public function assertUsage(AsciiString $url, NameType $purpose): void
            {
                TestCase::assertSame('urn:provider:credential', $url->value);
                TestCase::assertSame('PrivateProvider', $purpose->value);
                throw new \UnexpectedValueException('The credential provider rejected this operation.');
            }
        };
        $this->expectException(\UnexpectedValueException::class);
        $seed->validateFacts($this->facts(), $adapter);
    }

    public function testRequiredTimestampCannotBeSatisfiedBySelectingOnly(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('TimeStamp', new SignatureTimeStampSeed(new AsciiString('https://example.invalid/tsa'), true)));
        $choice = new SignatureSigningChoice($this->capabilities(), SignatureFormat::Pkcs7Detached, SignatureDigestMethod::SHA256, includeTimeStamp: true);
        $seed->validateChoice($choice, $this->facts());
        self::assertTrue($seed->requiresValidation());
        $this->expectException(\LogicException::class);
        $seed->validateEvidence($choice, new SignatureValue('unverified CMS bytes'), $this->facts(), new UnavailableSignatureEvidence());
    }

    public function testRequiredTimestampCannotBeOmitted(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('TimeStamp', new SignatureTimeStampSeed(new AsciiString('urn:tsa'), true)));
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateChoice($this->choice(), $this->facts());
    }

    public function testRequiredRevocationCannotBeOmittedOrUnverified(): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Revocation), $this->entry('AddRevInfo', new BooleanType(true)));
        $choice = new SignatureSigningChoice($this->capabilities(), SignatureFormat::Pkcs7Detached, SignatureDigestMethod::SHA256, includeRevocation: true);
        $seed->validateChoice($choice, $this->facts());
        $this->expectException(\LogicException::class);
        $seed->validateEvidence($choice, new SignatureValue('unverified CMS bytes'), $this->facts(), new UnavailableSignatureEvidence());
    }

    public function testEvidenceAdapterReceivesTheActualSignatureAndRejectsBadProof(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('TimeStamp', new SignatureTimeStampSeed(new AsciiString('urn:tsa'), true)));
        $actual = new SignatureValue('actual completed handler output');
        $choice = new SignatureSigningChoice($this->capabilities(), SignatureFormat::Pkcs7Detached, SignatureDigestMethod::SHA256, includeTimeStamp: true);
        $evidence = new class ($actual) implements SignatureSigningEvidence {
            public function __construct(private SignatureValue $expected)
            {
            }
            public function assertTimeStamp(?SignatureTimeStampSeed $seed, SignatureValue $signature): void
            {
                TestCase::assertSame($this->expected, $signature);
                TestCase::assertSame('urn:tsa', $seed->url->value);
                throw new \UnexpectedValueException('No verified timestamp token binds the actual signature imprint.');
            }
            public function assertRevocation(SignatureCertificateFacts $certificate, SignatureValue $signature): void
            {
                throw new \LogicException('Unexpected revocation operation.');
            }
        };
        $this->expectException(\UnexpectedValueException::class);
        $seed->validateEvidence($choice, $actual, $this->facts(), $evidence);
    }

    public function testPkcs1CannotIncludeRevocationData(): void
    {
        $capabilities = $this->capabilities(SignatureFormat::X509RsaSha1);
        $choice = new SignatureSigningChoice($capabilities, SignatureFormat::X509RsaSha1, SignatureDigestMethod::SHA1, includeRevocation: true);
        $this->expectException(\InvalidArgumentException::class);
        (new SignatureSeedValue())->validateChoice($choice, $this->facts());
    }

    public function testAdobeSeedsRequireActualCatalogExtensionLevel(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('V', new NumericType(3)));
        $catalog = new DictionaryType($this->entry('Extensions', new DictionaryType($this->entry('ADBE', new DictionaryType($this->entry('BaseVersion', new NameType('1.7')), $this->entry('ExtensionLevel', new IntegerType(3)))))));
        $seed->validateCatalog($catalog);
        self::assertSame('3.0', $seed->get('V')->toPdf(new SerializationContext()));
        $this->expectException(\InvalidArgumentException::class);
        $seed->validateCatalog(new DictionaryType());
    }

    public function testAdobeSeedsDoNotUseCatalogExtensionToBypassPdfVersion(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('AppearanceFilter', new TextString('named')));
        $this->expectException(\LogicException::class);
        $seed->validateVersion(new EffectivePdfVersion(new FileHeader(PdfVersion::PDF16), new DictionaryType()));
    }

    /** @return iterable<string, array{DictionaryType, bool}> */
    public static function rawSeedRequirements(): iterable
    {
        yield 'empty advisory' => [new DictionaryType(), false];
        yield 'optional reason' => [new DictionaryType(new Entry(new NameType('Reasons'), new ArrayType(new TextString('Review')))), false];
        yield 'mandatory absent reason' => [new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(8))), true];
        yield 'mandatory missing filter has no effect' => [new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(1))), false];
        yield 'mandatory present filter' => [new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(1)), new Entry(new NameType('Filter'), new NameType('custom'))), true];
        yield 'optional empty subfilter' => [new DictionaryType(new Entry(new NameType('SubFilter'), new ArrayType())), false];
        yield 'mandatory empty subfilter' => [new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(2)), new Entry(new NameType('SubFilter'), new ArrayType())), true];
        yield 'preferred subfilter normative' => [new DictionaryType(new Entry(new NameType('SubFilter'), new ArrayType(new NameType('adbe.pkcs7.detached')))), true];
        yield 'approval mdp' => [new DictionaryType(new Entry(new NameType('MDP'), new DictionaryType(new Entry(new NameType('P'), new IntegerType(0))))), true];
        yield 'empty mdp' => [new DictionaryType(new Entry(new NameType('MDP'), new DictionaryType())), false];
        yield 'required timestamp' => [new DictionaryType(new Entry(new NameType('TimeStamp'), new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(1))))), true];
        yield 'optional timestamp' => [new DictionaryType(new Entry(new NameType('TimeStamp'), new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(0))))), false];
        yield 'required revocation false' => [new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(32)), new Entry(new NameType('AddRevInfo'), new BooleanType(false))), true];
        yield 'unknown seed obligation' => [new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(1024))), true];
        yield 'invalid flags' => [new DictionaryType(new Entry(new NameType('Ff'), new NameType('bad'))), true];
        yield 'automatic lock' => [new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(128)), new Entry(new NameType('LockDocument'), new NameType('auto'))), false];
        yield 'required explicit unlock' => [new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(128)), new Entry(new NameType('LockDocument'), new NameType('false'))), true];
        yield 'mandatory certificate candidates' => [new DictionaryType(new Entry(new NameType('Cert'), new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(1)), new Entry(new NameType('Subject'), new ArrayType())))), true];
        yield 'advisory certificate candidates' => [new DictionaryType(new Entry(new NameType('Cert'), new DictionaryType(new Entry(new NameType('Subject'), new ArrayType())))), false];
        yield 'reserved cert bit' => [new DictionaryType(new Entry(new NameType('Cert'), new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(16))))), true];
        yield 'mandatory browser url ignored' => [new DictionaryType(new Entry(new NameType('Cert'), new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(64)), new Entry(new NameType('URL'), new AsciiString('urn:test'))))), false];
        yield 'mandatory private url' => [new DictionaryType(new Entry(new NameType('Cert'), new DictionaryType(new Entry(new NameType('Ff'), new IntegerType(64)), new Entry(new NameType('URL'), new AsciiString('urn:test')), new Entry(new NameType('URLType'), new NameType('private'))))), true];
    }

    #[DataProvider('rawSeedRequirements')]
    public function testLegacyGeneratedDictionaryCannotBypassMandatorySeedGuard(DictionaryType $seed, bool $required): void
    {
        self::assertSame($required, (new SignatureSeedValidationRequirement())->requiresValidation($seed));
    }

    public function testExplicitRealsPreserveWholeAndFractionalPdfSyntax(): void
    {
        $context = new SerializationContext();
        self::assertSame('3.0', (new RealType(3))->toPdf($context));
        self::assertSame('1000.0', (new RealType(1e3))->toPdf($context));
        self::assertSame('0.25', (new RealType(.25))->toPdf($context));
    }

    public function testImmutableSeedCannotRemoveItsRequiredFlag(): void
    {
        $this->expectException(\LogicException::class);
        (new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Reasons)))->without('Ff');
    }
}

/** Test-only normalized facts: the public RSA fixture is parsed and its self-signature is verified. */
final readonly class SignaturePolicyCertificateFactsFixture implements SignatureCertificateFacts
{
    /** @var array<string, string> */
    private array $subject;

    public function __construct(private ByteString $der, private SignatureKeyAlgorithm $algorithm, private ?CertificateKeyUsage $usage)
    {
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der->value), 64, "\n") . "-----END CERTIFICATE-----\n";
        $certificate = openssl_x509_read($pem);
        $parsed = $certificate === false ? false : openssl_x509_parse($certificate);
        $public = $certificate === false ? false : openssl_pkey_get_public($certificate);
        if ($parsed === false || $public === false || openssl_x509_verify($certificate, $public) !== 1) {
            throw new \UnexpectedValueException('The normalized test fixture must have a valid self-signature.');
        }
        $this->subject = $parsed['subject'];
    }

    public function certificate(): ByteString
    {
        return $this->der;
    }
    public function issuers(): array
    {
        return [$this->der];
    }
    public function policies(): array
    {
        return [];
    }
    public function keyUsage(): ?CertificateKeyUsage
    {
        return $this->usage;
    }
    public function keyAlgorithm(): SignatureKeyAlgorithm
    {
        return $this->algorithm;
    }
    public function containsSubject(CertificateSubjectName $required): bool
    {
        foreach ($required->entries() as $attribute) {
            if (($this->subject[$attribute->key->value] ?? null) !== $attribute->value->text) {
                return false;
            }
        }
        return true;
    }
}
