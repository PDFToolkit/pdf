<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Metadata\Declarations\Declaration;
use PDF\Document\Metadata\Declarations\Declarations;
use PDF\Document\Metadata\MetadataEntry;
use PDF\Document\Metadata\MetadataStream;
use PDF\Document\Metadata\MetadataUri;
use PDF\Document\Metadata\XmlText;
use PDF\Document\Metadata\XmpLanguageAlternative;
use PDF\Document\Metadata\XmpName;
use PDF\Document\Metadata\XmpPacket;
use PDF\Document\Structure\AttributeAssociation;
use PDF\Document\Structure\MarkInformation;
use PDF\Document\Structure\StandardStructureNamespace;
use PDF\Document\Structure\StructureAttributeObject;
use PDF\Document\Structure\StructureAttributes;
use PDF\Document\Structure\StructureElement;
use PDF\Document\Structure\StructureInclusionRules;
use PDF\Document\Structure\StructureNamespace;
use PDF\Document\Structure\StructureNamespaces;
use PDF\Document\Structure\StructureRole;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\Document\Structure\WtPdfAudit;
use PDF\Document\Structure\WtPdfConformanceLevel;
use PDF\Document\Structure\WtPdfFindingStatus;
use PDF\Document\Structure\WtPdfFontUsageChecks;
use PDF\Document\Structure\WtPdfGraphChecks;
use PDF\Document\Structure\WtPdfStructureChecks;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextRenderingMode;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Text\TextRenderingMode;
use PHPUnit\Framework\TestCase;

final class WtPdfAuditTest extends TestCase
{
    public function testAValidMechanicalSubsetStillRequiresHumanReview(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $document->addKid($this->element('P', $pdf));
        $catalog = $this->catalog($root, WtPdfConformanceLevel::Accessibility);
        $report = (new WtPdfAudit())->inspect($catalog, new EffectivePdfVersion(new FileHeader('2.0'), $catalog), WtPdfConformanceLevel::Accessibility);
        self::assertSame([], $report->failures());
        self::assertNotEmpty($report->pending());
        self::assertSame(WtPdfFindingStatus::HumanReviewRequired, $report->pending()[0]->status);
        $report->assertNoDetectedFailures();
    }

    public function testPdf17CannotPassWtPdfAudit(): void
    {
        [$root] = $this->tree();
        $catalog = $this->catalog($root, WtPdfConformanceLevel::Reuse);
        $report = (new WtPdfAudit())->inspect($catalog, new EffectivePdfVersion(new FileHeader('1.7'), $catalog), WtPdfConformanceLevel::Reuse);
        self::assertContains('6.2', array_map(static fn ($finding): string => $finding->clause, $report->failures()));
    }

    public function testAccessibleFigureNeedsAlternativeWhileReuseDoesNotRequireIt(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $document->addKid($this->element('Figure', $pdf));
        self::assertSame([], (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse));
        $findings = (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Accessibility);
        self::assertSame('8.2.5.28.2', $findings[0]->clause);
    }

    public function testUnnumberedHeadingIsForbiddenEvenThoughTs32005PermitsIt(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $document->addKid($this->element('H', $pdf));
        self::assertSame('8.2.5.12', (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse)[0]->clause);
    }

    public function testCaptionCannotAppearInTheMiddleOfItsSiblings(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $figure = $this->element('Figure', $pdf);
        $figure->addKid($this->element('P', $pdf))->addKid($this->element('Caption', $pdf))->addKid($this->element('P', $pdf));
        $document->addKid($figure);
        self::assertSame('8.2.5.27', (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse)[0]->clause);
    }

    public function testLabelledListRequiresAnExplicitNumberingStyle(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $list = $this->element('L', $pdf);
        $list->addKid($this->element('LI', $pdf)->addKid($this->element('Lbl', $pdf))->addKid($this->element('LBody', $pdf)));
        $document->addKid($list);
        self::assertSame('8.2.5.25', (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse)[0]->clause);
    }

    public function testLabelledListWithExplicitDecimalNumberingPassesMechanicalChecks(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $list = $this->element('L', $pdf, $this->attributes('List', new Entry(new NameType('ListNumbering'), new NameType('Decimal'))));
        $list->addKid($this->element('LI', $pdf)->addKid($this->element('Lbl', $pdf))->addKid($this->element('LBody', $pdf)));
        $document->addKid($list);
        self::assertSame([], (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse));
    }

    public function testUnequalLogicalTableWidthsAreDetected(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $table = $this->element('Table', $pdf);
        $table->addKid($this->element('TR', $pdf)->addKid($this->element('TD', $pdf)));
        $table->addKid($this->element('TR', $pdf)->addKid($this->element('TD', $pdf))->addKid($this->element('TD', $pdf)));
        $document->addKid($table);
        self::assertSame('8.2.5.26', (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse)[0]->clause);
    }

    public function testTableGridAccountsForBothRowAndColumnSpans(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $table = $this->element('Table', $pdf);
        $table->addKid($this->element('TR', $pdf)->addKid($this->element('TD', $pdf, $this->attributes('Table', new Entry(new NameType('RowSpan'), new IntegerType(2)))))->addKid($this->element('TD', $pdf, $this->attributes('Table', new Entry(new NameType('ColSpan'), new IntegerType(2))))));
        $table->addKid($this->element('TR', $pdf)->addKid($this->element('TD', $pdf))->addKid($this->element('TD', $pdf)));
        $document->addKid($table);
        self::assertSame([], (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse));
    }

    public function testTableCellCannotCrossRowGroupBoundaries(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $table = $this->element('Table', $pdf);
        $head = $this->element('THead', $pdf)->addKid($this->element('TR', $pdf)->addKid($this->element('TH', $pdf, $this->attributes('Table', new Entry(new NameType('RowSpan'), new IntegerType(2))))));
        $body = $this->element('TBody', $pdf)->addKid($this->element('TR', $pdf)->addKid($this->element('TD', $pdf)));
        $table->addKid($head)->addKid($body);
        $document->addKid($table);
        self::assertSame('8.2.5.26', (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse)[0]->clause);
    }

    public function testTableSpanExpansionIsBounded(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $table = $this->element('Table', $pdf)->addKid($this->element('TR', $pdf)->addKid($this->element('TD', $pdf, $this->attributes('Table', new Entry(new NameType('ColSpan'), new IntegerType(1000000))))));
        $document->addKid($table);
        $finding = (new WtPdfStructureChecks(10))->inspect($root, WtPdfConformanceLevel::Reuse)[0];
        self::assertSame('8.2.5.26', $finding->clause);
        self::assertStringContainsString('budget', $finding->message);
    }

    public function testFootnotesRequireReciprocalReferences(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $paragraph = $this->element('P', $pdf);
        $note = $this->element('FENote', $pdf);
        $paragraph->addReference($note);
        $document->addKid($paragraph)->addKid($note);
        self::assertSame('8.2.5.14.1', (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse)[0]->clause);
        $note->addReference($paragraph);
        self::assertSame([], (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse));
    }

    public function testMissingMetadataAndDisplayTitleAreReported(): void
    {
        [$root] = $this->tree();
        $catalog = new DocumentCatalog($root->pages, ...$root->catalogEntries(new MarkInformation(new BooleanType(true))));
        $findings = (new WtPdfGraphChecks())->inspect($catalog, $root, WtPdfConformanceLevel::Accessibility);
        self::assertSame(['8.11.1', '6.1.3', '8.11.2'], array_map(static fn ($finding): string => $finding->clause, $findings));
    }

    public function testXfaAndPrivateUseTextAreDetected(): void
    {
        [$root] = $this->tree();
        $catalog = $this->catalog($root, WtPdfConformanceLevel::Reuse)->with(new Entry(new NameType('AcroForm'), new DictionaryType(new Entry(new NameType('XFA'), new LiteralString('xfa')))))->with(new Entry(new NameType('Title'), new TextString("Private\u{E000}")));
        $clauses = array_map(static fn ($finding): string => $finding->clause, (new WtPdfGraphChecks())->inspect($catalog, $root, WtPdfConformanceLevel::Reuse));
        self::assertContains('8.10.1', $clauses);
        self::assertContains('8.6', $clauses);
    }

    public function testAccessibilityOptionalContentNeedsNamesAndForbidsAutomaticStates(): void
    {
        [$root] = $this->tree();
        $configuration = new DictionaryType(new Entry(new NameType('AS'), new ArrayType()));
        $catalog = $this->catalog($root, WtPdfConformanceLevel::Accessibility)->with(new Entry(new NameType('OCProperties'), new DictionaryType(new Entry(new NameType('D'), $configuration), new Entry(new NameType('Configs'), new ArrayType($configuration)))));
        $findings = (new WtPdfGraphChecks())->inspect($catalog, $root, WtPdfConformanceLevel::Accessibility);
        self::assertCount(4, array_filter($findings, static fn ($finding): bool => $finding->clause === '8.7'));
    }

    public function testDeprecatedAnnotationsAndMissingPageTabOrderAreDetected(): void
    {
        $annotation = new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Movie')));
        $page = $this->page(new DictionaryType(), null, new Entry(new NameType('Annots'), new ArrayType(new IndirectReference($annotation))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $clauses = array_map(static fn ($finding): string => $finding->clause, (new WtPdfGraphChecks())->inspect($catalog, null, WtPdfConformanceLevel::Reuse));
        self::assertContains('8.9.3.3', $clauses);
        self::assertContains('8.9.1', $clauses);
    }

    public function testRenderedStandardFontMustBeEmbeddedButInvisibleTextIsExempt(): void
    {
        foreach ([0, 3] as $mode) {
            $font = new Type1Font(StandardFont::Helvetica, new Entry(new NameType('Encoding'), new NameType('WinAnsiEncoding')));
            $resources = new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
            $content = new StreamObject(new ContentStreams(new BeginTextObject(), new SetTextFontAndSize(new NameType('F1'), new IntegerType(12)), new SetTextRenderingMode(TextRenderingMode::from($mode)), new ShowText(new LiteralString('ABC')), new EndTextObject()));
            $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page($resources, $content)));
            $findings = (new WtPdfFontUsageChecks())->inspect($catalog);
            self::assertCount($mode === 0 ? 1 : 0, $findings);
            if ($mode === 0) {
                self::assertSame('8.4.5.5.1', $findings[0]->clause);
            }
        }
    }

    public function testExactDeclarationIdentifiersRetainTheirCaseAndSlash(): void
    {
        self::assertSame('http://pdfa.org/declarations/wtpdf/#reuse1.0', WtPdfConformanceLevel::Reuse->declarationIdentifier());
        self::assertSame('http://pdfa.org/declarations/wtpdf/#accessibility1.0', WtPdfConformanceLevel::Accessibility->declarationIdentifier());
    }

    public function testInvalidReferenceReturnsAnAuditFailureWithoutReindexingFatal(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $catalog = $this->catalog($root, WtPdfConformanceLevel::Reuse);
        $paragraph = $this->element('P', $pdf);
        $document->addKid($paragraph);
        $paragraph->addReference($this->element('FENote', $pdf));
        $report = (new WtPdfAudit())->inspect($catalog, new EffectivePdfVersion(new FileHeader('2.0'), $catalog), WtPdfConformanceLevel::Reuse);
        self::assertNotEmpty($report->failures());
        self::assertStringContainsString('Ref associations', implode(' ', array_map(static fn ($finding): string => $finding->message, $report->failures())));
    }

    public function testUnresolvedReferencedRoleProducesFindingsWithoutStorageAccessFailure(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $catalog = $this->catalog($root, WtPdfConformanceLevel::Reuse);
        $unknown = $this->element('UnknownRole', $pdf);
        $paragraph = $this->element('P', $pdf);
        $paragraph->addReference($unknown);
        $document->addKid($paragraph)->addKid($unknown);
        $report = (new WtPdfAudit())->inspect($catalog, new EffectivePdfVersion(new FileHeader('2.0'), $catalog), WtPdfConformanceLevel::Reuse);
        self::assertNotEmpty($report->failures());
        self::assertStringContainsString('role', strtolower(implode(' ', array_map(static fn ($finding): string => $finding->message, $report->failures()))));
    }

    public function testIndirectMarkAndDisplayTitleBooleansRemainValid(): void
    {
        [$root] = $this->tree();
        $catalog = $this->catalog($root, WtPdfConformanceLevel::Accessibility)
            ->with(new Entry(new NameType('MarkInfo'), new IndirectReference(new DictionaryType(new Entry(new NameType('Marked'), new IndirectReference(new BooleanType(true))), new Entry(new NameType('UserProperties'), new IndirectReference(new BooleanType(false))), new Entry(new NameType('Suspects'), new IndirectReference(new BooleanType(false)))))))
            ->with(new Entry(new NameType('ViewerPreferences'), new IndirectReference(new DictionaryType(new Entry(new NameType('DisplayDocTitle'), new IndirectReference(new BooleanType(true)))))));
        self::assertSame([], (new WtPdfGraphChecks())->inspect($catalog, $root, WtPdfConformanceLevel::Accessibility));
        $report = (new WtPdfAudit())->inspect($catalog, new EffectivePdfVersion(new FileHeader('2.0'), $catalog), WtPdfConformanceLevel::Accessibility);
        self::assertSame([], $report->failures());
    }

    public function testMalformedHumanTextEncodingIsReportedAsAnAuditFailure(): void
    {
        [$root] = $this->tree();
        $catalog = $this->catalog($root, WtPdfConformanceLevel::Reuse)->with(new Entry(new NameType('Title'), new LiteralString("\xFE\xFF\xD8\x00")));
        $report = (new WtPdfAudit())->inspect($catalog, new EffectivePdfVersion(new FileHeader('2.0'), $catalog), WtPdfConformanceLevel::Reuse);
        self::assertNotEmpty($report->failures());
        self::assertContains('6.2', array_map(static fn ($finding): string => $finding->clause, $report->failures()));
    }

    public function testRepeatedPageTreeIsReportedWithoutAnUncaughtTraversalError(): void
    {
        $page = new DictionaryType(new Entry(new NameType('Type'), new NameType('Page')), new Entry(new NameType('Resources'), new DictionaryType()));
        $pages = new DictionaryType(new Entry(new NameType('Type'), new NameType('Pages')), new Entry(new NameType('Kids'), new ArrayType(new IndirectReference($page), new IndirectReference($page))));
        $catalog = new DictionaryType(new Entry(new NameType('Type'), new NameType('Catalog')), new Entry(new NameType('Pages'), new IndirectReference($pages)));
        $report = (new WtPdfAudit())->inspect($catalog, new EffectivePdfVersion(new FileHeader('2.0'), $catalog), WtPdfConformanceLevel::Reuse);
        self::assertStringContainsString('repeated nodes', implode(' ', array_map(static fn ($finding): string => $finding->message, $report->failures())));
    }

    public function testRecursiveFormIsReportedAsACycleBeforeTheDepthBudgetIsExhausted(): void
    {
        $form = new class (new ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject(new NameType('Self')))) extends StreamObject {
            public function dictionary(): DictionaryType
            {
                return new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Form')), new Entry(new NameType('Resources'), new DictionaryType(new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Self'), new IndirectReference($this)))))));
            }
        };
        $resources = new DictionaryType(new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Form'), new IndirectReference($form)))));
        $content = new StreamObject(new ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject(new NameType('Form'))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page($resources, $content)));
        $report = (new WtPdfAudit(fonts: new WtPdfFontUsageChecks(maximumDepth: 2)))->inspect($catalog, new EffectivePdfVersion(new FileHeader('2.0'), $catalog), WtPdfConformanceLevel::Reuse);
        self::assertStringContainsString('cycle', implode(' ', array_map(static fn ($finding): string => $finding->message, $report->failures())));
    }

    public function testUnicodeMappingBudgetIsCumulativeAcrossDifferentCmaps(): void
    {
        $catalog = $this->multipleUnicodeMaps();
        self::assertNotEmpty((new WtPdfGraphChecks(maximumUnicodeMappings: 4))->inspect($catalog, null, WtPdfConformanceLevel::Reuse));
        $this->expectException(\OverflowException::class);
        (new WtPdfGraphChecks(maximumUnicodeMappings: 3))->inspect($catalog, null, WtPdfConformanceLevel::Reuse);
    }

    public function testTextByteBudgetIsCumulativeAcrossTextShowingOperations(): void
    {
        $font = new Type1Font(StandardFont::Helvetica, new Entry(new NameType('Encoding'), new NameType('WinAnsiEncoding')));
        $resources = new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
        $stream = new StreamObject(new ContentStreams(new BeginTextObject(), new SetTextFontAndSize(new NameType('F1'), new IntegerType(12)), new ShowText(new LiteralString('AB')), new ShowText(new LiteralString('CD')), new EndTextObject()));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page($resources, $stream)));
        self::assertNotEmpty((new WtPdfFontUsageChecks(maximumTextBytes: 4))->inspect($catalog));
        $this->expectException(\OverflowException::class);
        (new WtPdfAudit(fonts: new WtPdfFontUsageChecks(maximumTextBytes: 3)))->inspect($catalog, new EffectivePdfVersion(new FileHeader('2.0'), $catalog), WtPdfConformanceLevel::Reuse);
    }

    public function testAuditBudgetsMustBePositiveCallerPolicies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WtPdfFontUsageChecks(maximumTextBytes: 0);
    }

    private function multipleUnicodeMaps(): DictionaryType
    {
        $code = static fn (string $byte): \PDF\Document\Fonts\CMaps\CMapCode => new \PDF\Document\Fonts\CMaps\CMapCode(new \PDF\Syntax\Object\StringObject\HexadecimalString($byte));
        $mappings = new \PDF\Document\Fonts\CMaps\UnicodeMappings(
            new \PDF\Document\Fonts\CMaps\UnicodeChar($code('A'), (new \PDF\Document\Fonts\CMaps\UnicodeScalar(new IntegerType(65)))->sequence()),
            new \PDF\Document\Fonts\CMaps\UnicodeChar($code('B'), (new \PDF\Document\Fonts\CMaps\UnicodeScalar(new IntegerType(66)))->sequence()),
        );
        $spaces = new \PDF\Document\Fonts\CMaps\CodeSpaces(new \PDF\Document\Fonts\CMaps\CodeSpaceRange($code("\0"), $code("\xFF")));
        $first = new \PDF\Document\Fonts\ToUnicodeCMap(new \PDF\Document\Fonts\CMaps\UnicodeCMap(new \PDF\Document\Fonts\CMaps\CMapName(new NameType('First')), $spaces, $mappings));
        $second = new \PDF\Document\Fonts\ToUnicodeCMap(new \PDF\Document\Fonts\CMaps\UnicodeCMap(new \PDF\Document\Fonts\CMaps\CMapName(new NameType('Second')), $spaces, $mappings));
        return new DictionaryType(new Entry(new NameType('Type'), new NameType('Catalog')), new Entry(new NameType('First'), new IndirectReference($first)), new Entry(new NameType('Second'), new IndirectReference($second)));
    }

    /** @return array{StructureTreeRoot, StructureElement, StructureNamespace} */
    private function tree(): array
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $root = new StructureTreeRoot(new PageTreeNode(), namespaces: new StructureNamespaces($pdf), inclusionRules: new StructureInclusionRules());
        $document = $this->element('Document', $pdf);
        $root->addKid($document);
        return [$root, $document, $pdf];
    }

    private function element(string $name, StructureNamespace $namespace, ?StructureAttributes $attributes = null): StructureElement
    {
        return new StructureElement(new StructureRole(new NameType($name), $namespace), attributes: $attributes);
    }

    private function attributes(string $owner, Entry ...$entries): StructureAttributes
    {
        return new StructureAttributes(new AttributeAssociation(new StructureAttributeObject(new DictionaryType(new Entry(new NameType('O'), new NameType($owner)), ...$entries))));
    }

    private function catalog(StructureTreeRoot $root, WtPdfConformanceLevel $level): DocumentCatalog
    {
        $metadata = new MetadataStream(new XmpPacket(new Declarations(new Declaration(new MetadataUri($level->declarationIdentifier()))), new XmpLanguageAlternative(new XmpName('dc', new MetadataUri('http://purl.org/dc/elements/1.1/'), 'title'), new XmlText('Audit fixture'))));
        return new DocumentCatalog($root->pages, ...[...$root->catalogEntries(new MarkInformation(new BooleanType(true))), new MetadataEntry($metadata), new Entry(new NameType('ViewerPreferences'), new DictionaryType(new Entry(new NameType('DisplayDocTitle'), new BooleanType(true))))]);
    }

    private function page(DictionaryType $resources, ?StreamObject $contents = null, Entry ...$entries): PageObject
    {
        return new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new \PDF\Document\ResourceDictionary(...iterator_to_array($resources->entries())), $contents, ...$entries);
    }
}
