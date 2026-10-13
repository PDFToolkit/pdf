<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Forms\WidgetAppearance;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\FrozenSignatureGraph;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFieldSigning;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\Document\Signatures\SignatureValue;
use PDF\Document\Signatures\PolicySignatureBackend;
use PDF\Document\Signatures\SignatureOutputVerifier;
use PDF\Document\Signatures\SignatureVerificationInput;
use PDF\Document\Signatures\VerifiedSignatureFacts;
use PDF\Document\Signatures\SignatureAppearanceSelection;
use PDF\Document\Signatures\SignatureEvidenceCapability;
use PDF\Document\Signatures\Policies\CertificateSeedFlag;
use PDF\Document\Signatures\Policies\CertificateSeedFlags;
use PDF\Document\Signatures\Policies\CertificateSubjectName;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\Policies\SignatureCertificateSeedValue;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\Document\Signatures\Policies\SignatureSeedFlag;
use PDF\Document\Signatures\Policies\SignatureSeedFlags;
use PDF\Document\Signatures\Policies\SignatureSeedMdp;
use PDF\Document\Signatures\Policies\SignatureSeedValue;
use PDF\Document\Signatures\Policies\SignatureTimeStampSeed;
use PDF\Document\Signatures\Policies\SignatureHandlerCapabilities;
use PDF\Document\Signatures\Policies\SignatureCertificateFacts;
use PDF\Document\Signatures\Policies\SignatureCertificateUrlUsage;
use PDF\Document\Signatures\Policies\SignatureSigningEvidence;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\SignatureCmsInspection;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureFieldPolicySigningTest extends TestCase
{
    private function backend(): OpenSslSignatureBackend
    {
        return new OpenSslSignatureBackend(new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents(__DIR__ . '/Fixtures/signing/signer.der')))), file_get_contents(__DIR__ . '/Fixtures/signing/signer-private-key.pem')));
    }

    private function entry(string $key, \PDF\Syntax\FileStructure\BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function operation(?SignatureSeedValue $seed = null, SignatureSigningIntent $intent = new SignatureSigningIntent(), ?SignatureFieldLock $lock = null, ?SignatureFormat $format = null): SignatureFieldSigning
    {
        return new SignatureFieldSigning($this->backend(), new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), $intent, $seed, $lock, $format);
    }

    private function prepared(SignatureFieldSigning $operation, Entry ...$entries): PreparedSignature
    {
        $tree = (new FieldTree())->addRoot($operation->field);
        $catalogEntries = [$this->entry('AcroForm', new IndirectReference(new AcroForm($tree)))];
        if ($operation->certificationPermissions() !== null) {
            $catalogEntries[] = $this->entry('Perms', $operation->certificationPermissions());
        }
        $catalog = new DocumentCatalog(new PageTreeNode(), ...[...$catalogEntries, ...$entries]);
        $operation->bindCatalog(new IndirectReference($catalog));
        return (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $operation->signature);
    }

    private function raw(PendingSignature $signature, DictionaryType $seed): PreparedSignature
    {
        $field = new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($signature), seedValue: new IndirectReference($seed));
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($field)))));
        return (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $signature);
    }

    public function testFirstMatchingSubFilterActualDigestAndCertificateConstraintsAreAllApplied(): void
    {
        $backend = $this->backend();
        $facts = $backend->certificateFacts();
        $certificate = new SignatureCertificateSeedValue(
            new CertificateSeedFlags(CertificateSeedFlag::Subject, CertificateSeedFlag::Issuer, CertificateSeedFlag::SubjectDN, CertificateSeedFlag::KeyUsage),
            $this->entry('Subject', new ArrayType($facts->certificate())),
            $this->entry('Issuer', new ArrayType($facts->certificate())),
            $this->entry('SubjectDN', new ArrayType(new CertificateSubjectName($this->entry('CN', new TextString('PDFToolkit signer public test fixture'))))),
            $this->entry('KeyUsage', new ArrayType(new AsciiString('111000000')))
        );
        $seed = new SignatureSeedValue(
            new SignatureSeedFlags(SignatureSeedFlag::Filter, SignatureSeedFlag::SubFilter, SignatureSeedFlag::Reasons, SignatureSeedFlag::DigestMethod),
            $this->entry('Filter', new NameType('Adobe.PPKLite')),
            $this->entry('SubFilter', new ArrayType(new NameType('unknown'), new NameType('adbe.pkcs7.sha1'), new NameType('adbe.pkcs7.detached'))),
            $this->entry('Reasons', new ArrayType(new TextString('Étudié et approuvé'))),
            $this->entry('DigestMethod', new ArrayType(new NameType('SHA256'))),
            $this->entry('Cert', $certificate)
        );
        $operation = $this->operation($seed, new SignatureSigningIntent(reason: new TextString('Étudié et approuvé')));
        self::assertSame(SignatureFormat::Pkcs7Sha1, $operation->signature->format);
        $prepared = $this->prepared($operation);
        $signed = $operation->sign($prepared);
        $cms = new SignatureCmsInspection($signed->value->bytes, SignatureFormat::Pkcs7Sha1);
        self::assertSame(SignatureDigestMethod::SHA256, $cms->digest);
        self::assertSame(sha1($prepared->coveredBytes(), true), $cms->payload);
        self::assertStringContainsString('/SV ', $signed->revision->bytes);
    }

    public function testMandatoryDigestCannotClaimUnsupportedSha384FromPhpCms(): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::DigestMethod), $this->entry('DigestMethod', new ArrayType(new NameType('SHA384'))));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot emit a digest');
        $this->operation($seed);
    }

    public function testAdvisoryDigestDoesNotOverrideTheActualNativeDefault(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('DigestMethod', new ArrayType(new NameType('SHA384'))));
        $operation = $this->operation($seed);
        self::assertSame(SignatureDigestMethod::SHA256, $operation->choice->digest);
        self::assertNotEmpty($operation->sign($this->prepared($operation))->value->bytes);
    }

    public function testOptionalUnsupportedSubFilterAllowsAnExplicitPkcs1Fallback(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('SubFilter', new ArrayType(new NameType('unavailable'))));
        $operation = $this->operation($seed, format: SignatureFormat::X509RsaSha1);
        self::assertSame(SignatureFormat::X509RsaSha1, $operation->signature->format);
        self::assertSame(SignatureDigestMethod::SHA1, $operation->choice->digest);
        self::assertNotEmpty($operation->sign($this->prepared($operation))->value->bytes);
    }

    public function testARequiredHandlerCannotBeReplacedByAConvenientNativeHandler(): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Filter), $this->entry('Filter', new NameType('Other.Handler')));
        $this->expectException(\InvalidArgumentException::class);
        $this->operation($seed);
    }

    public function testMandatoryOmittedReasonActuallyOmitsTheEmittedReason(): void
    {
        $operation = $this->operation(new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Reasons)));
        $prepared = $this->prepared($operation);
        $signed = $operation->sign($prepared);
        self::assertFalse($signed->revision->binding($operation->signature)->dictionary->has('Reason'));
        self::assertNotEmpty($signed->value->bytes);
    }

    public function testRawFinalizationStillCannotBypassAMandatoryReason(): void
    {
        $operation = $this->operation(new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Reasons)));
        $prepared = $this->prepared($operation);
        $this->expectException(\LogicException::class);
        $prepared->finalize(new SignatureValue('unverified bytes'));
    }

    public function testExternalFinalizationVerifiesMathematicsBeforeHonoringSeedChoices(): void
    {
        $operation = $this->operation(new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Reasons)));
        $prepared = $this->prepared($operation);
        $this->expectException(\InvalidArgumentException::class);
        $prepared->finalizeWithPolicy(new SignatureValue('unverified bytes'), $operation->backend);
    }

    public function testExternalFinalizationAcceptsTheActualNativeVerifiedOutput(): void
    {
        $operation = $this->operation(new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Reasons)));
        $prepared = $this->prepared($operation);
        $value = $operation->backend->signWithDigest($prepared->coveredBytes(), $operation->signature->format, $operation->choice->digest);
        $signed = $prepared->finalizeWithPolicy($value, $operation->backend);
        self::assertSame($value, $signed->value);
    }

    public function testFrozenSeedsCannotBeChangedToWaiveAnActualEmittedConstraint(): void
    {
        $seed = new MutableNativeSignatureSeedFixture();
        $signature = new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached, entries: $this->entry('Reason', new TextString('Forged')));
        $prepared = $this->raw($signature, $seed);
        $seed->forged = true;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actual signature Reason');
        $prepared->signWithPolicy($this->backend());
    }

    public function testAChangedPrototypeCannotInvalidateTheOriginalValidFrozenChoice(): void
    {
        $seed = new MutableNativeSignatureSeedFixture();
        $signature = new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached, entries: $this->entry('Reason', new TextString('Approved')));
        $prepared = $this->raw($signature, $seed);
        $seed->forged = true;
        self::assertNotEmpty($prepared->signWithPolicy($this->backend())->value->bytes);
    }

    public function testNativeFieldLockConstructsAndVerifiesTheCopiedDirectFieldMdp(): void
    {
        $lock = new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('order'), new TextString('customer.name')));
        $operation = $this->operation(lock: $lock);
        $prepared = $this->prepared($operation);
        $signed = $operation->sign($prepared);
        self::assertStringContainsString('/TransformMethod /FieldMDP /TransformParams <<', $signed->revision->bytes);
        self::assertStringContainsString('/Action /Include /V /1.2 /Fields [(order) (customer.name)]', $signed->revision->bytes);
        self::assertStringContainsString('/Data 1 0 R', $signed->revision->bytes);
    }

    public function testAFieldLockWithoutItsReferenceCannotBeSignedByThePolicyPath(): void
    {
        $signature = new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached);
        $field = new SignatureField(new PartialFieldName(new TextString('approval')), lock: new SignatureFieldLock(SignatureLockAction::All), value: new IndirectReference($signature));
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($field)))));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $signature);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('copies the Action');
        $prepared->signWithPolicy($this->backend());
    }

    public function testMdpSeedConstructsAnActualFirstCertificationAndActualPermissionsTarget(): void
    {
        $seed = new SignatureSeedValue(entries: $this->entry('MDP', new SignatureSeedMdp(new IntegerType(2))));
        $operation = $this->operation($seed, new SignatureSigningIntent(certification: DocMdpPermissions::FormFillingTemplatesAndSigning));
        $prepared = $this->prepared($operation);
        $signed = $operation->sign($prepared);
        self::assertStringContainsString('/TransformMethod /DocMDP', $signed->revision->bytes);
        $graph = new FrozenSignatureGraph($signed->revision);
        self::assertSame((string) $signed->revision->binding($operation->signature)->identifier, (string) $graph->record($graph->catalog->get('Perms')->get('DocMDP'))->identifier);
    }

    public function testClaimedCertificationWithoutAnActualTransformCannotSatisfyMdp(): void
    {
        $seed = new DictionaryType($this->entry('MDP', new DictionaryType($this->entry('P', new IntegerType(2)))));
        $signature = new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached);
        $prepared = $this->raw($signature, $seed);
        $this->expectException(\InvalidArgumentException::class);
        $prepared->signWithPolicy($this->backend(), new SignatureSigningIntent(certification: DocMdpPermissions::FormFillingTemplatesAndSigning));
    }

    public function testNativeCertificationCannotComeAfterAnAlreadySignedApproval(): void
    {
        $first = $this->operation();
        $tree = (new FieldTree())->addRoot($first->field);
        $form = new AcroForm($tree);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference($form)));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $first->signature);
        $signed = $first->sign($prepared);
        $next = new SignatureFieldSigning($this->backend(), new PartialFieldName(new TextString('certification')), new SignatureCapacity(8192), new SignatureSigningIntent(certification: DocMdpPermissions::NoChanges));
        $tree->addRoot($next->field);
        $revision = (new IncrementalUpdate($signed->revision, new FileBody($form)))->toRevision();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('first signed field');
        (new SignatureWriter())->prepareRevision($revision, $next->signature);
    }

    public function testEachSubsequentApprovalUsesItsOwnIncrementalSnapshot(): void
    {
        $first = $this->operation();
        $tree = (new FieldTree())->addRoot($first->field);
        $form = new AcroForm($tree);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference($form)));
        $signed = $first->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $first->signature));
        $next = new SignatureFieldSigning($this->backend(), new PartialFieldName(new TextString('second')), new SignatureCapacity(8192));
        $tree->addRoot($next->field);
        $revision = (new IncrementalUpdate($signed->revision, new FileBody($form)))->toRevision();
        $prepared = (new SignatureWriter())->prepareRevision($revision, $next->signature);
        $second = $next->sign($prepared);
        self::assertSame($signed->revision, $second->revision->previous);
        self::assertStringStartsWith($signed->revision->bytes, $second->revision->bytes);
        self::assertGreaterThan($signed->range->afterOffset + $signed->range->afterLength, $second->range->afterOffset + $second->range->afterLength);
    }

    public function testRemovingThePreviouslySignedFieldDoesNotMakeCertificationFirst(): void
    {
        $first = $this->operation();
        $prepared = $this->prepared($first);
        $signed = $first->sign($prepared);
        $next = new SignatureFieldSigning($this->backend(), new PartialFieldName(new TextString('certification')), new SignatureCapacity(8192), new SignatureSigningIntent(certification: DocMdpPermissions::NoChanges));
        $previousGraph = new FrozenSignatureGraph($signed->revision);
        $catalog = new DocumentCatalog($previousGraph->catalog->get('Pages'), $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($next->field)))));
        $replacement = new IndirectObject($catalog, $signed->revision->binding($signed->revision->root->target())->identifier);
        $revision = (new IncrementalUpdate($signed->revision, new FileBody($replacement)))->toRevision();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('first signed field');
        (new SignatureWriter())->prepareRevision($revision, $next->signature);
    }

    public function testTwoReservationsCannotBeFinalizedInTheSameRevision(): void
    {
        $first = $this->operation();
        $next = new SignatureFieldSigning($this->backend(), new PartialFieldName(new TextString('second')), new SignatureCapacity(8192));
        $tree = (new FieldTree())->addRoot($first->field)->addRoot($next->field);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm($tree))));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $first->signature);
        $signed = $first->sign($prepared);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('new incremental revision');
        (new SignatureWriter())->prepareRevision($signed->revision, $next->signature);
    }

    public function testTheSameReservationInTwoFieldsIsNotAnUnambiguousPolicyBinding(): void
    {
        $first = $this->operation();
        $next = new SignatureField(new PartialFieldName(new TextString('second')), value: new IndirectReference($first->signature));
        $tree = (new FieldTree())->addRoot($first->field)->addRoot($next);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm($tree))));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unambiguous');
        (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $first->signature);
    }

    public function testARecordOutsideTheActualFieldTreeCannotInventASeedObligation(): void
    {
        $operation = $this->operation();
        $tree = (new FieldTree())->addRoot($operation->field);
        $catalog = new DocumentCatalog(new PageTreeNode(), $this->entry('AcroForm', new IndirectReference(new AcroForm($tree))));
        $other = new DictionaryType($this->entry('V', new IndirectReference($operation->signature)), $this->entry('SV', new DictionaryType($this->entry('Ff', new IntegerType(8)))));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog, $other)), $operation->signature);
        self::assertNotEmpty($prepared->sign($operation->backend)->value->bytes);
    }

    public function testNativeLegalAttestationIsBoundToTheActualCatalogText(): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::LegalAttestation), $this->entry('LegalAttestation', new ArrayType(new TextString('Reviewed sources'))));
        $operation = $this->operation($seed, new SignatureSigningIntent(legalAttestation: new TextString('Reviewed sources')));
        $prepared = $this->prepared($operation, $this->entry('Legal', new DictionaryType($this->entry('Attestation', new TextString('Reviewed sources')))));
        self::assertNotEmpty($operation->sign($prepared)->value->bytes);
    }

    public function testClaimingALegalAttestationWithoutEmittingItIsRejected(): void
    {
        $operation = $this->operation(intent: new SignatureSigningIntent(legalAttestation: new TextString('Reviewed sources')));
        $prepared = $this->prepared($operation);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actual catalog Legal');
        $operation->sign($prepared);
    }

    public function testRequiredNamedAppearanceIsActuallyAttachedToItsWidget(): void
    {
        $box = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(30));
        $stream = new FormAppearanceStream(new ContentStreams(), $box, new DictionaryType());
        $selection = new SignatureAppearanceSelection(new TextString('Explicit appearance'), new IndirectReference($stream));
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::AppearanceFilter), $this->entry('AppearanceFilter', $selection->name));
        $operation = $this->operation($seed, new SignatureSigningIntent(appearance: $selection));
        $widget = new WidgetAnnotation($box, new WidgetAppearance($stream));
        $operation->field->addWidget($widget);
        $page = new PageObject($box, new ResourceDictionary(), null, $this->entry('Annots', new ArrayType(new IndirectReference($widget))));
        $widget->bindToPage($page);
        $pages = (new PageTreeNode())->addKid($page);
        $catalog = new DocumentCatalog(
            $pages,
            $this->entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($operation->field)))),
            $this->entry('Extensions', new DictionaryType($this->entry('ADBE', new DictionaryType($this->entry('BaseVersion', new NameType('1.7')), $this->entry('ExtensionLevel', new IntegerType(3))))))
        );
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $operation->signature);
        self::assertNotEmpty($operation->sign($prepared)->value->bytes);
    }

    public function testNativeOutputCannotChangeTheNegotiatedCredentialMidOperation(): void
    {
        $backend = new MutablePolicyBackendFixture($this->backend(), $this->backendForFixture('recipient'));
        $operation = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192));
        $prepared = $this->prepared($operation);
        $this->expectException(\InvalidArgumentException::class);
        $operation->sign($prepared);
    }

    private function backendForFixture(string $name): OpenSslSignatureBackend
    {
        return new OpenSslSignatureBackend(new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents(__DIR__ . '/Fixtures/signing/' . $name . '.der')))), file_get_contents(__DIR__ . '/Fixtures/signing/' . $name . '-private-key.pem')));
    }

    public function testAClaimedSupportedDigestMustMatchTheVerifiedActualOutput(): void
    {
        $backend = new MutablePolicyBackendFixture($this->backend(), falseDigest: true);
        $operation = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192));
        $prepared = $this->prepared($operation);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('verified actual digest');
        $operation->sign($prepared);
    }

    public function testVerifierResultsCannotBeReplayedAcrossDifferentPreparedBytes(): void
    {
        $one = $this->operation(intent: new SignatureSigningIntent(reason: new TextString('one')));
        $first = $this->prepared($one);
        $value = $one->backend->signWithDigest($first->coveredBytes(), $one->signature->format, $one->choice->digest);
        $proof = $one->backend->outputVerifier()->verify(new SignatureVerificationInput($first->coveredBytes(), $value, $one->signature->format, $one->backend->certificateChain()));
        $verifier = new class ($proof) implements SignatureOutputVerifier {
            public function __construct(private VerifiedSignatureFacts $proof)
            {
            }
            public function verify(SignatureVerificationInput $input): VerifiedSignatureFacts
            {
                return $this->proof;
            }
        };
        $two = $this->operation(intent: new SignatureSigningIntent(reason: new TextString('two')));
        $second = $this->prepared($two);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exact input/output bytes');
        $second->finalizeWithPolicy($value, $two->backend, $two->intent, verifier: $verifier);
    }

    public function testAdvertisedTimestampCapabilityDoesNotReplaceEvidenceOfTheActualCms(): void
    {
        $backend = new MutablePolicyBackendFixture($this->backend(), evidenceCapabilities: true);
        $seed = new SignatureSeedValue(entries: $this->entry('TimeStamp', new SignatureTimeStampSeed(new AsciiString('https://example.invalid/tsa'), true)));
        $operation = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), new SignatureSigningIntent(includeTimeStamp: true), $seed);
        $prepared = $this->prepared($operation);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('RFC3161');
        $operation->sign($prepared);
    }

    public function testAdvertisedRevocationCapabilityDoesNotReplaceEvidenceOfTheActualCms(): void
    {
        $backend = new MutablePolicyBackendFixture($this->backend(), evidenceCapabilities: true);
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Revocation), $this->entry('AddRevInfo', new BooleanType(true)));
        $operation = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), new SignatureSigningIntent(includeRevocation: true), $seed);
        $prepared = $this->prepared($operation);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('revocation');
        $operation->sign($prepared);
    }

    public function testOversizedOutputIsRejectedBeforeInvokingAnyExternalVerifier(): void
    {
        $operation = new SignatureFieldSigning($this->backend(), new PartialFieldName(new TextString('approval')), new SignatureCapacity(32));
        $prepared = $this->prepared($operation);
        $verifier = new class () implements SignatureOutputVerifier {
            public function verify(SignatureVerificationInput $input): VerifiedSignatureFacts
            {
                throw new \RuntimeException('The external verifier must not be called.');
            }
        };
        $this->expectException(\LengthException::class);
        $prepared->finalizeWithPolicy(new SignatureValue(str_repeat('x', 33)), $operation->backend, verifier: $verifier);
    }

    public function testNativeDsaCannotSilentlyIgnoreTheSeedSha1Rule(): void
    {
        $backend = $this->backendForFixture('dsa-1024');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot emit a digest');
        new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), seed: new SignatureSeedValue(entries: $this->entry('DigestMethod', new ArrayType(new NameType('SHA256')))));
    }

    public function testNativeDsaWithoutSeedsUsesItsActuallyVerifiedCmsSha256(): void
    {
        $backend = $this->backendForFixture('dsa-1024');
        $operation = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192));
        $signed = $operation->sign($this->prepared($operation));
        self::assertSame(SignatureDigestMethod::SHA256, (new SignatureCmsInspection($signed->value->bytes, $operation->signature->format))->digest);
    }

    public function testNativeDsaWithAnEmptySeedUsesItsActuallyVerifiedCmsSha256(): void
    {
        $operation = new SignatureFieldSigning($this->backendForFixture('dsa-1024'), new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), seed: new SignatureSeedValue());
        $signed = $operation->sign($this->prepared($operation));
        self::assertSame(SignatureDigestMethod::SHA256, (new SignatureCmsInspection($signed->value->bytes, $operation->signature->format))->digest);
    }

    public function testNativeDsaReasonsSeedDoesNotInventADigestMethodConstraint(): void
    {
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Reasons), $this->entry('Reasons', new ArrayType(new TextString('Approved'))));
        $operation = new SignatureFieldSigning($this->backendForFixture('dsa-1024'), new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), new SignatureSigningIntent(reason: new TextString('Approved')), $seed);
        $signed = $operation->sign($this->prepared($operation));
        self::assertSame(SignatureDigestMethod::SHA256, (new SignatureCmsInspection($signed->value->bytes, $operation->signature->format))->digest);
        self::assertSame('Approved', $signed->revision->binding($operation->signature)->dictionary->get('Reason')->value);
    }

    public function testAnUnknownMandatoryRawSeedFlagFailsExplicitly(): void
    {
        $prepared = $this->raw(new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached), new DictionaryType($this->entry('Ff', new IntegerType(512))));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unknown or reserved seed flags');
        $prepared->signWithPolicy($this->backend());
    }

    public function testRequiredPrivateCertificateUrlUsesAnExplicitHandlerAndTheFrozenUrl(): void
    {
        $usage = new class () implements SignatureCertificateUrlUsage {
            public int $calls = 0;
            public function assertUsage(AsciiString $url, NameType $purpose): void
            {
                $this->check($url, $purpose);
                ++$this->calls;
            }
            private function check(AsciiString $url, NameType $purpose): void
            {
                if ($url->value !== 'https://example.invalid/certificate' || $purpose->value !== 'Private.Type') {
                    throw new \InvalidArgumentException('The explicit URL handler received other metadata.');
                }
            }
        };
        $certificate = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::URL), $this->entry('URL', new AsciiString('https://example.invalid/certificate')), $this->entry('URLType', new NameType('Private.Type')));
        $seed = new SignatureSeedValue(entries: $this->entry('Cert', $certificate));
        $operation = new SignatureFieldSigning($this->backend(), new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), seed: $seed, urlUsage: $usage);
        self::assertNotEmpty($operation->sign($this->prepared($operation))->value->bytes);
        self::assertSame(3, $usage->calls);
    }

    public function testExternalEvidenceValidatorReceivesTheActualCryptographicallyVerifiedCms(): void
    {
        $backend = new MutablePolicyBackendFixture($this->backend(), evidenceCapabilities: true);
        $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Revocation), $this->entry('AddRevInfo', new BooleanType(true)));
        $operation = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), new SignatureSigningIntent(includeRevocation: true), $seed);
        $prepared = $this->prepared($operation);
        $evidence = new class () implements SignatureSigningEvidence {
            public ?SignatureValue $observed = null;
            public function assertTimeStamp(?SignatureTimeStampSeed $seed, SignatureValue $signature): void
            {
                throw new \LogicException('No RFC3161 evidence was supplied.');
            }
            public function assertRevocation(SignatureCertificateFacts $certificate, SignatureValue $signature): void
            {
                $this->observed = $signature;
                throw new \InvalidArgumentException('The emitted CMS contains no verified revocation evidence.');
            }
        };
        try {
            $operation->sign($prepared, evidence: $evidence);
            self::fail('Missing revocation evidence cannot finalize.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('no verified revocation evidence', $error->getMessage());
            self::assertNotNull($evidence->observed);
            $input = new SignatureVerificationInput($prepared->coveredBytes(), $evidence->observed, $operation->signature->format, $backend->certificateChain());
            self::assertSame(SignatureDigestMethod::SHA256, $backend->outputVerifier()->verify($input)->algorithms->digest);
        }
    }

    /** @return iterable<string, array{SignatureSeedValue, string}> */
    public static function unsupportedEvidence(): iterable
    {
        yield 'timestamp' => [new SignatureSeedValue(entries: new Entry(new NameType('TimeStamp'), new SignatureTimeStampSeed(new AsciiString('https://example.invalid/tsa'), true))), 'external'];
        yield 'revocation' => [new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Revocation), new Entry(new NameType('AddRevInfo'), new BooleanType(true))), 'external'];
    }

    #[DataProvider('unsupportedEvidence')]
    public function testMandatoryEvidenceFailsExplicitlyBeforeNativeSigning(SignatureSeedValue $seed, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        $this->operation($seed);
    }
}

final class MutableNativeSignatureSeedFixture extends DictionaryType
{
    public bool $forged = false;
    public function entries(): iterable
    {
        yield new Entry(new NameType('Ff'), new IntegerType(8));
        yield new Entry(new NameType('Reasons'), new ArrayType(new TextString($this->forged ? 'Forged' : 'Approved')));
    }
}

/** Simulates a buggy service changing identity or ignoring the requested digest; verification must reject it. */
final class MutablePolicyBackendFixture implements PolicySignatureBackend
{
    public function __construct(private OpenSslSignatureBackend $current, private ?OpenSslSignatureBackend $switch = null, private bool $falseDigest = false, private bool $evidenceCapabilities = false)
    {
    }
    public function capabilities(): SignatureHandlerCapabilities
    {
        return $this->current->capabilities();
    }
    public function supportedDigests(SignatureFormat $format): array
    {
        return $this->falseDigest ? [SignatureDigestMethod::SHA384] : $this->current->supportedDigests($format);
    }
    public function certificateFacts(): SignatureCertificateFacts
    {
        return $this->current->certificateFacts();
    }
    public function certificateChain(): CertificateChain
    {
        return $this->current->certificateChain();
    }
    public function outputVerifier(): SignatureOutputVerifier
    {
        return $this->current->outputVerifier();
    }
    public function supportsEvidence(SignatureEvidenceCapability $capability): bool
    {
        return $this->evidenceCapabilities;
    }
    public function validateVersion(EffectivePdfVersion $version, SignatureFormat $format): void
    {
        $this->current->validateVersion($version, $format);
    }
    public function sign(string $coveredBytes, SignatureFormat $format): SignatureValue
    {
        return $this->current->sign($coveredBytes, $format);
    }
    public function signWithDigest(string $coveredBytes, SignatureFormat $format, SignatureDigestMethod $digest): SignatureValue
    {
        if ($this->switch !== null) {
            $this->current = $this->switch;
        }
        return $this->current->sign($coveredBytes, $format);
    }
}
