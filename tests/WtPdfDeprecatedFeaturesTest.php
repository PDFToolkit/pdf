<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\ResourceDictionary;
use PDF\Document\Structure\WtPdfAudit;
use PDF\Document\Structure\WtPdfAuditReport;
use PDF\Document\Structure\WtPdfConformanceLevel;
use PDF\Document\Structure\WtPdfDeprecatedCatalogue;
use PDF\Document\Structure\WtPdfDeprecatedChecks;
use PDF\Document\Structure\WtPdfDeprecatedFileContext;
use PDF\Document\Structure\WtPdfDeprecatedLimits;
use PDF\Document\Structure\WtPdfDeprecatedScope;
use PDF\Document\Structure\WtPdfFinding;
use PDF\Document\Structure\WtPdfFindingStatus;
use PDF\Document\Structure\WtPdfGraphChecks;
use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzeroObsolete;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WtPdfDeprecatedFeaturesTest extends TestCase
{
    private function e(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function d(Entry ...$entries): DictionaryType
    {
        return new DictionaryType(...$entries);
    }

    private function page(Entry ...$entries): DictionaryType
    {
        return $this->d($this->e('Type', new NameType('Page')), ...$entries);
    }

    private function catalog(Entry ...$entries): DictionaryType
    {
        return $this->d($this->e('Type', new NameType('Catalog')), ...$entries);
    }

    private function pages(DictionaryType $page): DictionaryType
    {
        return $this->catalog($this->e('Pages', $this->d($this->e('Type', new NameType('Pages')), $this->e('Kids', new ArrayType(new IndirectReference($page))))));
    }

    private function resource(string $kind, BasicType $value): DictionaryType
    {
        return $this->pages($this->page($this->e('Resources', $this->d($this->e($kind, $this->d($this->e('Resource', new IndirectReference($value))))))));
    }

    private function field(DictionaryType $field): DictionaryType
    {
        return $this->catalog($this->e('AcroForm', $this->d($this->e('Fields', new ArrayType(new IndirectReference($field))))));
    }

    private function signature(DictionaryType $signature): DictionaryType
    {
        return $this->field($this->d($this->e('FT', new NameType('Sig')), $this->e('V', new IndirectReference($signature))));
    }

    private function structure(DictionaryType $element): DictionaryType
    {
        return $this->catalog($this->e('StructTreeRoot', $this->d($this->e('K', new IndirectReference($element)))));
    }

    /**
     * @param list<WtPdfFinding> $findings
     * @return list<string>
     */
    private function recommendations(array $findings): array
    {
        $identifiers = [];
        foreach ($findings as $finding) {
            if ($finding->status === WtPdfFindingStatus::Recommendation) {
                $identifiers[] = explode(':', $finding->message, 2)[0];
                self::assertSame('6.2', $finding->clause);
                self::assertSame(10, $finding->pdfPage);
            }
        }
        return $identifiers;
    }

    /** @return iterable<string,array{string}> */
    public static function graphFeatures(): iterable
    {
        foreach (['catalog-needs-rendering', 'forms-xfa', 'forms-need-appearances', 'alternate-presentations', 'resources-procset', 'filespec-os-names', 'embedded-mac-params', 'xobject-name', 'xobject-opi', 'font-name', 'font-type1-charset', 'font-cidset', 'cid-collections', 'extgstate-transfer', 'blend-mode-array', 'blend-mode-compatible', 'viewer-prepress', 'annotations-legacy', 'actions-legacy', 'launch-os', 'signature-seed-sha1', 'signature-legacy-subfilter', 'signature-handler-version', 'signature-ur', 'signature-reference-digest', 'legal-legacy-counts', 'markinfo-suspects', 'structure-revisions', 'printfield-checked', 'web-capture', 'page-separations', 'trapping-appearance', 'operator-F', 'encrypted-fdf'] as $identifier) {
            yield $identifier => [$identifier];
        }
    }

    #[DataProvider('graphFeatures')]
    public function testEachReviewedMechanicalFamilyHasAnActualContextualCounterexample(string $identifier): void
    {
        $empty = new DictionaryType();
        $legacyFont = $this->d($this->e('Subtype', new NameType('Type1')), $this->e('FontDescriptor', $this->d($this->e('CharSet', new TextString('/A')))));
        $embedded = new StreamObject('', $this->d($this->e('Subtype', new NameType('application/vnd.fdf')), $this->e('EncryptionRevision', new IntegerType(1)), $this->e('Params', $this->d($this->e('Mac', $empty)))));
        $cid = $this->d($this->e('Subtype', new NameType('CIDFontType2')), $this->e('FontDescriptor', $this->d($this->e('CIDSet', new StreamObject('bits')))));
        $composite = $this->d($this->e('Subtype', new NameType('Type0')), $this->e('DescendantFonts', new ArrayType($cid)));
        $trapAppearance = new StreamObject(new ContentStreams(), $this->d($this->e('Subtype', new NameType('Form'))));
        $trap = $this->d($this->e('Subtype', new NameType('TrapNet')), $this->e('AP', $this->d($this->e('N', $trapAppearance))));
        $catalog = match ($identifier) {
            'catalog-needs-rendering' => $this->catalog($this->e('NeedsRendering', new BooleanType(false))),
            'forms-xfa' => $this->catalog($this->e('AcroForm', $this->d($this->e('XFA', new StreamObject('XDP'))))),
            'forms-need-appearances' => $this->catalog($this->e('AcroForm', $this->d($this->e('NeedAppearances', new BooleanType(false))))),
            'alternate-presentations' => $this->catalog($this->e('Names', $this->d($this->e('AlternatePresentations', $empty)))),
            'resources-procset' => $this->pages($this->page($this->e('Resources', $this->d($this->e('ProcSet', new ArrayType(new NameType('PDF'))))))),
            'filespec-os-names' => $this->catalog($this->e('AF', new ArrayType($this->d($this->e('DOS', new TextString('legacy.pdf')))))),
            'embedded-mac-params', 'encrypted-fdf' => $this->catalog($this->e('AF', new ArrayType($this->d($this->e('EF', $this->d($this->e('F', new IndirectReference($embedded)))))))),
            'xobject-name' => $this->resource('XObject', new StreamObject('', $this->d($this->e('Subtype', new NameType('Image')), $this->e('Name', new NameType('Legacy'))))),
            'xobject-opi' => $this->resource('XObject', new StreamObject('', $this->d($this->e('Subtype', new NameType('Form')), $this->e('OPI', $empty)))),
            'font-name' => $this->resource('Font', $this->d($this->e('Subtype', new NameType('Type3')), $this->e('Name', new NameType('Legacy')))),
            'font-type1-charset' => $this->resource('Font', $legacyFont),
            'font-cidset' => $this->resource('Font', $composite),
            'cid-collections' => $this->resource('Font', $this->d($this->e('Subtype', new NameType('Type0')), $this->e('Encoding', new NameType('KSC-EUC-H')))),
            'extgstate-transfer' => $this->resource('ExtGState', $this->d($this->e('TR2', new NameType('Default')))),
            'blend-mode-array' => $this->resource('ExtGState', $this->d($this->e('BM', new ArrayType(new NameType('Normal'))))),
            'blend-mode-compatible' => $this->resource('ExtGState', $this->d($this->e('BM', new NameType('Compatible')))),
            'viewer-prepress' => $this->catalog($this->e('ViewerPreferences', $this->d($this->e('PrintClip', new NameType('CropBox'))))),
            'annotations-legacy' => $this->pages($this->page($this->e('Annots', new ArrayType($this->d($this->e('Subtype', new NameType('Sound'))))))),
            'actions-legacy' => $this->catalog($this->e('OpenAction', $this->d($this->e('S', new NameType('Movie'))))),
            'launch-os' => $this->catalog($this->e('OpenAction', $this->d($this->e('S', new NameType('Launch')), $this->e('Win', $empty)))),
            'signature-seed-sha1' => $this->field($this->d($this->e('FT', new NameType('Sig')), $this->e('SV', $this->d($this->e('DigestMethod', new ArrayType(new NameType('SHA1'))))))),
            'signature-legacy-subfilter' => $this->signature($this->d($this->e('SubFilter', new NameType('adbe.pkcs7.sha1')))),
            'signature-handler-version' => $this->signature($this->d($this->e('R', new IntegerType(1)))),
            'signature-ur' => $this->catalog($this->e('Perms', $this->d($this->e('UR3', $empty)))),
            'signature-reference-digest' => $this->signature($this->d($this->e('Reference', new ArrayType($this->d($this->e('DigestMethod', new NameType('MD5'))))))),
            'legal-legacy-counts' => $this->catalog($this->e('Legal', $this->d($this->e('SoundActions', new IntegerType(0))))),
            'markinfo-suspects' => $this->catalog($this->e('MarkInfo', $this->d($this->e('Suspects', new BooleanType(false))))),
            'structure-revisions' => $this->structure($this->d($this->e('Type', new NameType('StructElem')), $this->e('R', new IntegerType(0)))),
            'printfield-checked' => $this->structure($this->d($this->e('Type', new NameType('StructElem')), $this->e('A', $this->d($this->e('O', new NameType('PrintField')), $this->e('checked', new NameType('on')))))),
            'web-capture' => $this->pages($this->page($this->e('ID', new TextString('web-id')))),
            'page-separations' => $this->pages($this->page($this->e('SeparationInfo', $empty))),
            'trapping-appearance' => $this->pages($this->page($this->e('Annots', new ArrayType($trap)))),
            'operator-F' => $this->pages($this->page($this->e('Contents', new StreamObject(new ContentStreams(new FillPathNonzeroObsolete()))))),
            default => throw new \LogicException('Every tested mechanical family has its own constructed context.'),
        };
        $findings = (new WtPdfDeprecatedChecks())->inspect($catalog, new WtPdfDeprecatedFileContext($empty));
        self::assertContains($identifier, $this->recommendations($findings));
        self::assertSame([], (new WtPdfAuditReport(WtPdfConformanceLevel::Reuse, ...$findings))->failures());
    }

    /** @return iterable<string,array{string}> */
    public static function fileFeatures(): iterable
    {
        foreach (['info-metadata', 'encryption-version', 'standard-revision', 'crypt-filter-method', 'crypt-filter-length', 'assistive-restriction'] as $identifier) {
            yield $identifier => [$identifier];
        }
    }

    #[DataProvider('fileFeatures')]
    public function testFileFeaturesRequireTheSuppliedTrailerRatherThanGuessedCatalogKeys(string $identifier): void
    {
        $encryption = $this->d($this->e('Filter', new NameType('Standard')), $this->e('V', new IntegerType(4)), $this->e('R', new IntegerType(4)), $this->e('P', new IntegerType(-516)), $this->e('CF', $this->d($this->e('StdCF', $this->d($this->e('CFM', new NameType('AESV2')), $this->e('Length', new IntegerType(16)))))));
        $info = $this->d($this->e('Title', new TextString('Old metadata')));
        $trailer = $this->d($this->e('Info', new IndirectReference($info)), $this->e('Encrypt', new IndirectReference($encryption)));
        $findings = (new WtPdfDeprecatedChecks())->inspect($this->catalog(), new WtPdfDeprecatedFileContext($trailer));
        self::assertContains($identifier, $this->recommendations($findings));
        self::assertSame([], (new WtPdfDeprecatedChecks())->inspect($this->catalog($this->e('Info', $info), $this->e('Encrypt', $encryption)), new WtPdfDeprecatedFileContext(new DictionaryType())));
    }

    public function testCatalogueMechanicalRowsHaveExecutedCounterexamplesAndRetainedSourceIdentities(): void
    {
        $graph = array_map(static fn (array $case): string => $case[0], iterator_to_array(self::graphFeatures()));
        $file = array_map(static fn (array $case): string => $case[0], iterator_to_array(self::fileFeatures()));
        $sourceHash = hash_file('sha256', __DIR__ . '/../resources/reference-documents/ISO_32000-2_sponsored_EC3.pdf');
        foreach ((new WtPdfDeprecatedCatalogue())->features as $identifier => $feature) {
            self::assertSame('ISO_32000-2_sponsored_EC3.pdf', $feature->source->document);
            self::assertSame($sourceHash, $feature->source->documentSha256);
            if ($feature->scope === WtPdfDeprecatedScope::ConstructedGraph) {
                self::assertContains($identifier, $graph);
            } elseif ($feature->scope === WtPdfDeprecatedScope::SuppliedFileContext) {
                self::assertContains($identifier, $file);
            }
        }
    }

    public function testPrivateDomainsDoNotBecomeFontsActionsInfoOrEncryptionByTheirKeyNames(): void
    {
        $private = $this->d($this->e('Type', new NameType('Font')), $this->e('Subtype', new NameType('Type1')), $this->e('Name', new NameType('Private')), $this->e('R', new IntegerType(1)), $this->e('S', new NameType('Movie')), $this->e('TR', new NameType('Identity')), $this->e('ID', new TextString('private-id')), $this->e('Info', $this->d($this->e('Title', new TextString('private')))));
        $catalog = $this->catalog($this->e('Private', new IndirectReference($private)), $this->e('AF', new ArrayType($this->d($this->e('F', new TextString('file.pdf')), $this->e('ID', new ArrayType(new TextString('first'), new TextString('second')))))));
        self::assertSame([], (new WtPdfDeprecatedChecks())->inspect($catalog, new WtPdfDeprecatedFileContext($this->d($this->e('ID', new ArrayType(new TextString('first'), new TextString('second')))))));
    }

    public function testInfoDatesAndThreadInfoAreExceptionsAndNullMetadataIsAbsent(): void
    {
        $info = $this->d($this->e('CreationDate', new TextString('D:20261015')), $this->e('ModDate', new TextString('D:20261015')), $this->e('Title', new NullType()));
        $threadInfo = $this->d($this->e('Title', new TextString('Thread title')));
        $catalog = $this->catalog($this->e('Threads', new ArrayType($this->d($this->e('I', $threadInfo)))));
        self::assertSame([], (new WtPdfDeprecatedChecks())->inspect($catalog, new WtPdfDeprecatedFileContext($this->d($this->e('Info', new IndirectReference($info))))));
    }

    public function testPrivateAnnotationFsAndActionKeysDoNotCreateUnspecifiedRelationships(): void
    {
        $annotation = $this->d($this->e('Subtype', new NameType('Text')), $this->e('FS', $this->d($this->e('Mac', new TextString('private')))), $this->e('A', $this->d($this->e('S', new NameType('Movie')))));
        self::assertSame([], (new WtPdfDeprecatedChecks())->inspect($this->pages($this->page($this->e('Annots', new ArrayType($annotation)))), new WtPdfDeprecatedFileContext(new DictionaryType())));
    }

    public function testAdditionalActionsAreSelectedByTheOwnersStandardEvents(): void
    {
        $sound = $this->d($this->e('S', new NameType('Sound')));
        $aa = $this->d($this->e('Private', $sound), $this->e('O', $sound));
        $catalog = $this->catalog($this->e('AA', $aa)); // O is a page event, not a catalog event.
        self::assertSame([], (new WtPdfDeprecatedChecks())->inspect($catalog, new WtPdfDeprecatedFileContext(new DictionaryType())));
        self::assertContains('actions-legacy', $this->recommendations((new WtPdfDeprecatedChecks())->inspect($this->pages($this->page($this->e('AA', $aa))))));
    }

    public function testHalftoneTransferAndFormIdsAreNotGraphicsStateTransferOrWebCapture(): void
    {
        $halftone = $this->d($this->e('TransferFunction', new NameType('Identity')), $this->e('TR', new NameType('Private')));
        $form = new StreamObject(new ContentStreams(), $this->d($this->e('Subtype', new NameType('Form')), $this->e('ID', new TextString('extension-id'))));
        $resources = $this->d($this->e('ExtGState', $this->d($this->e('G', $this->d($this->e('HT', $halftone))))), $this->e('XObject', $this->d($this->e('Form', $form))));
        self::assertSame([], (new WtPdfDeprecatedChecks())->inspect($this->pages($this->page($this->e('Resources', $resources))), new WtPdfDeprecatedFileContext(new DictionaryType())));
    }

    public function testIgnoredImageMaskOpiAndRedefinedIdentityCryptFilterAreNotActiveFeatures(): void
    {
        $image = new StreamObject('', $this->d($this->e('Subtype', new NameType('Image')), $this->e('ImageMask', new BooleanType(true)), $this->e('OPI', $this->d())));
        $encrypt = $this->d($this->e('Filter', new NameType('Standard')), $this->e('V', new IntegerType(5)), $this->e('R', new IntegerType(6)), $this->e('P', new IntegerType(-4)), $this->e('CF', $this->d($this->e('Identity', $this->d($this->e('CFM', new NameType('V2')), $this->e('Length', new IntegerType(5)))))));
        self::assertSame([], (new WtPdfDeprecatedChecks())->inspect($this->resource('XObject', $image), new WtPdfDeprecatedFileContext($this->d($this->e('Encrypt', $encrypt)))));
    }

    public function testDescriptorFieldsAreCheckedOnlyForTheirActualFontKind(): void
    {
        $descriptor = $this->d($this->e('CharSet', new TextString('/A')), $this->e('CIDSet', new StreamObject('bits')));
        foreach (['TrueType' => [], 'Type1' => ['font-type1-charset'], 'CIDFontType2' => ['font-cidset']] as $kind => $expected) {
            $font = $this->d($this->e('Subtype', new NameType($kind)), $this->e('FontDescriptor', new IndirectReference($descriptor)));
            self::assertSame($expected, $this->recommendations((new WtPdfDeprecatedChecks())->inspect($this->resource('Font', $font))));
        }
    }

    public function testStandardCheckedAndPrivateLayoutCheckedAreNotDeprecatedPrintFieldSpelling(): void
    {
        foreach ([$this->d($this->e('O', new NameType('PrintField')), $this->e('Checked', new NameType('on'))), $this->d($this->e('O', new NameType('Layout')), $this->e('checked', new NameType('private')))] as $attribute) {
            $element = $this->d($this->e('Type', new NameType('StructElem')), $this->e('A', $attribute));
            self::assertSame([], (new WtPdfDeprecatedChecks())->inspect($this->structure($element), new WtPdfDeprecatedFileContext(new DictionaryType())));
        }
    }

    public function testSeedAndSignatureFieldsDoNotConfuseDetachedCmsWithSha1Format(): void
    {
        $signature = $this->d($this->e('SubFilter', new NameType('adbe.pkcs7.detached')), $this->e('Contents', new TextString('opaque-CMS')));
        $child = $this->d($this->e('V', $signature), $this->e('SV', $this->d($this->e('DigestMethod', new ArrayType(new NameType('SHA256'))))));
        $catalog = $this->field($this->d($this->e('FT', new NameType('Sig')), $this->e('Kids', new ArrayType($child))));
        $findings = (new WtPdfDeprecatedChecks())->inspect($catalog, new WtPdfDeprecatedFileContext(new DictionaryType()));
        self::assertSame([], $this->recommendations($findings));
        self::assertCount(1, $findings);
        self::assertSame(WtPdfFindingStatus::AdditionalValidationRequired, $findings[0]->status);
        self::assertStringContainsString('signature-cms-digests', $findings[0]->message);
    }

    public function testOpaqueProgramsAndMissingFileFactsRemainExplicitExternalChecks(): void
    {
        $catalog = $this->pages($this->page($this->e('Contents', new StreamObject('BT (F is ordinary string data) Tj ET'))));
        $findings = (new WtPdfDeprecatedChecks())->inspect($catalog);
        self::assertSame([], $this->recommendations($findings));
        self::assertCount(2, $findings);
        self::assertStringContainsString('no application-supplied trailer', $findings[0]->message);
        self::assertStringContainsString('raw-content-operators', $findings[1]->message);
    }

    public function testSharedObjectsAreInspectedPerDomainAndReportedOnlyOncePerFeature(): void
    {
        $shared = $this->d($this->e('Subtype', new NameType('Type1')), $this->e('Name', new NameType('old')), $this->e('TR', new NameType('Identity')));
        $resources = $this->d($this->e('Font', $this->d($this->e('F1', new IndirectReference($shared)), $this->e('F2', new IndirectReference($shared)))), $this->e('ExtGState', $this->d($this->e('G1', new IndirectReference($shared)))));
        $ids = $this->recommendations((new WtPdfDeprecatedChecks())->inspect($this->pages($this->page($this->e('Resources', $resources)))));
        sort($ids);
        self::assertSame(['extgstate-transfer', 'font-name'], $ids);
    }

    /** @return iterable<string,array{WtPdfDeprecatedLimits}> */
    public static function budgets(): iterable
    {
        yield 'objects' => [new WtPdfDeprecatedLimits(maximumObjects: 1)];
        yield 'edges' => [new WtPdfDeprecatedLimits(maximumEdges: 1)];
        yield 'depth' => [new WtPdfDeprecatedLimits(maximumDepth: 1)];
        yield 'operations' => [new WtPdfDeprecatedLimits(maximumOperations: 1)];
    }

    #[DataProvider('budgets')]
    public function testBudgetExhaustionReportsIncompleteInspectionRatherThanDocumentNonconformance(WtPdfDeprecatedLimits $limits): void
    {
        $catalog = $this->pages($this->page($this->e('Contents', new StreamObject(new ContentStreams(new BeginTextObject(), new EndTextObject())))));
        $findings = (new WtPdfDeprecatedChecks(limits: $limits))->inspect($catalog, new WtPdfDeprecatedFileContext(new DictionaryType()));
        self::assertSame([], (new WtPdfAuditReport(WtPdfConformanceLevel::Reuse, ...$findings))->failures());
        self::assertStringContainsString('incomplete', implode(' ', array_map(static fn (WtPdfFinding $finding): string => $finding->message, $findings)));
    }

    public function testCyclesAreDeduplicatedWithoutFollowingPrivateOrParentEdges(): void
    {
        $action = new class () extends DictionaryType {
            public function entries(): iterable
            {
                yield new Entry(new NameType('S'), new NameType('Movie'));
                yield new Entry(new NameType('Next'), new IndirectReference($this));
            }
        };
        $findings = (new WtPdfDeprecatedChecks(limits: new WtPdfDeprecatedLimits(maximumObjects: 10)))->inspect($this->catalog($this->e('OpenAction', $action)), new WtPdfDeprecatedFileContext(new DictionaryType()));
        self::assertSame(['actions-legacy'], $this->recommendations($findings));
        self::assertCount(1, $findings);
    }

    public function testRecommendationsAreDistinctFromFailuresAndPendingJudgments(): void
    {
        $report = new WtPdfAuditReport(
            WtPdfConformanceLevel::Reuse,
            new WtPdfFinding('6.2', 10, WtPdfFindingStatus::Recommendation, 'Discouraged feature.'),
            new WtPdfFinding('6.2', 10, WtPdfFindingStatus::AdditionalValidationRequired, 'Opaque payload.'),
            new WtPdfFinding('8.2', 14, WtPdfFindingStatus::HumanReviewRequired, 'Semantic judgment.'),
        );
        self::assertCount(1, $report->recommendations());
        self::assertCount(2, $report->pending());
        self::assertSame([], $report->failures());
        $report->assertNoDetectedFailures();
    }

    public function testLegacyAnnotationsKeepTheirSeparateShallFailure(): void
    {
        $catalog = $this->pages($this->page($this->e('Annots', new ArrayType($this->d($this->e('Subtype', new NameType('Movie')))))));
        $failures = (new WtPdfGraphChecks())->inspect($catalog, null, WtPdfConformanceLevel::Reuse);
        self::assertNotEmpty(array_filter($failures, static fn (WtPdfFinding $finding): bool => $finding->clause === '8.9.1' && $finding->status === WtPdfFindingStatus::Failed));
        self::assertContains('annotations-legacy', $this->recommendations((new WtPdfDeprecatedChecks())->inspect($catalog)));
    }

    public function testInspectionDoesNotBanTheNativeWriterFromHistoricalPdfFeatures(): void
    {
        $resources = new ResourceDictionary($this->e('ProcSet', new ArrayType(new NameType('PDF'))));
        $content = new StreamObject(new ContentStreams(new AppendRectangle(new IntegerType(0), new IntegerType(0), new IntegerType(10), new IntegerType(10)), new FillPathNonzeroObsolete()));
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), $resources, $content);
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $revision = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        self::assertStringContainsString("\nF\n", $revision->bytes);
        $ids = $this->recommendations((new WtPdfDeprecatedChecks())->inspect($catalog, new WtPdfDeprecatedFileContext(new DictionaryType())));
        sort($ids);
        self::assertSame(['operator-F', 'resources-procset'], $ids);
        $report = (new WtPdfAudit())->inspect($catalog, new EffectivePdfVersion(new FileHeader('2.0'), $catalog), WtPdfConformanceLevel::Reuse);
        self::assertCount(2, $report->recommendations());
    }
}
