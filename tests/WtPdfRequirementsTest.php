<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Structure\AttributeAssociation;
use PDF\Document\Structure\StandardStructureNamespace;
use PDF\Document\Structure\StructureAttributeObject;
use PDF\Document\Structure\StructureAttributes;
use PDF\Document\Structure\StructureElement;
use PDF\Document\Structure\StructureInclusionRules;
use PDF\Document\Structure\StructureNamespace;
use PDF\Document\Structure\StructureNamespaces;
use PDF\Document\Structure\StructureRole;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\Document\Structure\WtPdfConformanceLevel;
use PDF\Document\Structure\WtPdfFindingStatus;
use PDF\Document\Structure\WtPdfGraphChecks;
use PDF\Document\Structure\WtPdfListChecks;
use PDF\Document\Structure\WtPdfStructureChecks;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

/** WTPDF physical pages 18, 21, 23–25, 32 and 44–47. */
final class WtPdfRequirementsTest extends TestCase
{
    public function testDefaultLanguageIsMandatoryAndMayBeIndirect(): void
    {
        $graph = new WtPdfGraphChecks();
        foreach ([null, new LiteralString(''), new TextString('   ')] as $language) {
            $catalog = $language === null ? new DictionaryType() : new DictionaryType(new Entry(new NameType('Lang'), $language));
            self::assertContains('8.4.4', $this->clauses($graph->inspect($catalog, null, WtPdfConformanceLevel::Reuse)));
        }
        $catalog = new DictionaryType(new Entry(new NameType('Lang'), new IndirectReference(new TextString('fr-CA'))));
        self::assertNotContains('8.4.4', $this->clauses($graph->inspect($catalog, null, WtPdfConformanceLevel::Reuse)));
    }

    public function testEmptyLanguageOverrideDoesNotInheritTheCatalogLanguage(): void
    {
        $catalog = new DictionaryType(new Entry(new NameType('Lang'), new TextString('en')), new Entry(new NameType('Object'), new DictionaryType(new Entry(new NameType('Lang'), new TextString('')))));
        self::assertContains('8.4.4', $this->clauses((new WtPdfGraphChecks())->inspect($catalog, null, WtPdfConformanceLevel::Reuse)));
    }

    public function testRichTextRequiresPlainValueAndEquivalenceRemainsExplicit(): void
    {
        $field = new DictionaryType(new Entry(new NameType('FT'), new NameType('Tx')), new Entry(new NameType('RV'), new LiteralString('<body>Text</body>')));
        $graph = new WtPdfGraphChecks();
        self::assertContains('8.10.3.3', $this->clauses($graph->inspect($field, null, WtPdfConformanceLevel::Reuse)));
        $field = $field->with(new Entry(new NameType('V'), new IndirectReference(new TextString('Text'))));
        $results = array_values(array_filter($graph->inspect($field, null, WtPdfConformanceLevel::Reuse), static fn ($finding): bool => $finding->clause === '8.10.3.3'));
        self::assertCount(1, $results);
        self::assertSame(WtPdfFindingStatus::AdditionalValidationRequired, $results[0]->status);
    }

    public function testTociRequiresAReferenceOnItselfOrADescendant(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $old = new StructureNamespace(StandardStructureNamespace::PDF17);
        $root = new StructureTreeRoot($root->pages, namespaces: new StructureNamespaces($pdf, $old), inclusionRules: new StructureInclusionRules());
        $root->addKid($document = $this->element('Document', $pdf));
        $item = $this->element('TOCI', $old);
        $document->addKid($this->element('TOC', $old)->addKid($item));
        $target = $this->element('P', $pdf);
        $document->addKid($target);
        self::assertContains('8.2.5.8', $this->structureClauses($root));
        $item->addKid($this->element('Reference', $old)->addReference($target));
        self::assertNotContains('8.2.5.8', $this->structureClauses($root));
    }

    public function testEmptyRubyIsNotACompleteRubyPair(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $ruby = $this->element('Ruby', $pdf);
        $document->addKid($this->element('P', $pdf)->addKid($ruby));
        self::assertContains('8.2.5.23', $this->structureClauses($root));
        $ruby->addKid($this->element('RB', $pdf))->addKid($this->element('RT', $pdf));
        self::assertSame([], $this->structureClauses($root));
    }

    public function testFenoteAttributeHasItsOwnClosedDomain(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $document->addKid($this->element('FENote', $pdf, $this->attributes('FENote', 'NoteType', new NameType('Footnotes'))));
        self::assertContains('8.2.5.14.2', $this->structureClauses($root));
    }

    public function testNoninteractiveFormNeedsPrintFieldRole(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $document->addKid($this->element('Form', $pdf));
        self::assertContains('8.10.4', $this->structureClauses($root));
        [$root, $document, $pdf] = $this->tree();
        $document->addKid($this->element('Form', $pdf, $this->attributes('PrintField', 'Role', new NameType('tv'))));
        self::assertSame([], $this->structureClauses($root));
    }

    public function testContinuedListIncludesFirstSegmentInReferenceRequirements(): void
    {
        [$root, $first, $second] = $this->continued();
        $second->addReference($first);
        self::assertContains('8.2.5.25', $this->structureClauses($root));
        $first->addReference($second);
        self::assertSame([], $this->structureClauses($root));
    }

    public function testContinuedListMustResolveItsPreviousIdentifier(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $document->addKid($this->element('L', $pdf, $this->continuedAttributes('missing')));
        self::assertContains('8.2.5.25', $this->structureClauses($root));
    }

    public function testContinuedListAssociationBudgetIsExplicit(): void
    {
        [$root, $first, $second] = $this->continued();
        $first->addReference($second);
        $second->addReference($first);
        $this->expectException(\OverflowException::class);
        (new WtPdfStructureChecks(lists: new WtPdfListChecks(1)))->inspect($root, WtPdfConformanceLevel::Reuse);
    }

    public function testHeaderIdentifiersMustResolveToHeadersOfTheirOwnTable(): void
    {
        foreach (['head', 'other', 'missing'] as $headerId) {
            [$root, $document, $pdf] = $this->tree();
            $head = new StructureElement(new StructureRole(new NameType('TH'), $pdf), identifier: new LiteralString('head'));
            $foreign = new StructureElement(new StructureRole(new NameType('TH'), $pdf), identifier: new LiteralString('other'));
            $cell = $this->element('TD', $pdf, $this->attributes('Table', 'Headers', new ArrayType(new LiteralString($headerId))));
            $document->addKid($this->element('Table', $pdf)->addKid($this->element('TR', $pdf)->addKid($head)->addKid($cell)));
            $document->addKid($this->element('Table', $pdf)->addKid($this->element('TR', $pdf)->addKid($foreign)));
            self::assertSame($headerId === 'head' ? [] : ['8.2.5.26'], $this->structureClauses($root));
        }
    }

    public function testPuaTextRequiresAnAlternativeOnlyForRealAccessibilityContent(): void
    {
        foreach (['missing', 'ActualText', 'Artifact', 'private-alternative', 'ancestor-alternative'] as $case) {
            $catalog = $this->privateUseText($case);
            $fontChecks = new \PDF\Document\Structure\WtPdfFontUsageChecks();
            $reuse = $this->clauses($fontChecks->inspect($catalog, WtPdfConformanceLevel::Reuse));
            self::assertNotContains('8.4.3', $reuse);
            $access = $this->clauses($fontChecks->inspect($catalog, WtPdfConformanceLevel::Accessibility));
            self::assertSame($case === 'missing', in_array('8.4.3', $access, true), $case);
            self::assertSame($case === 'private-alternative', in_array('8.6', $access, true), $case);
        }
    }

    public function testExplicitPdfUaIdentificationMustIncludeBothValues(): void
    {
        foreach ([['2', null], ['1', '2024'], ['2', '2024']] as [$part, $revision]) {
            $properties = [new \PDF\Document\Metadata\XmpTextProperty(new \PDF\Document\Metadata\XmpName('pdfuaid', new \PDF\Document\Metadata\MetadataUri('http://www.aiim.org/pdfua/ns/id/'), 'part'), new \PDF\Document\Metadata\XmlText($part))];
            if ($revision !== null) {
                $properties[] = new \PDF\Document\Metadata\XmpTextProperty(new \PDF\Document\Metadata\XmpName('pdfuaid', new \PDF\Document\Metadata\MetadataUri('http://www.aiim.org/pdfua/ns/id/'), 'rev'), new \PDF\Document\Metadata\XmlText($revision));
            }
            $catalog = new DictionaryType(new \PDF\Document\Metadata\MetadataEntry(new \PDF\Document\Metadata\MetadataStream(new \PDF\Document\Metadata\XmpPacket(...$properties))));
            $clauses = $this->clauses((new WtPdfGraphChecks())->inspect($catalog, null, WtPdfConformanceLevel::Accessibility));
            self::assertSame($part !== '2' || $revision !== '2024', in_array('C', $clauses, true));
        }
    }

    public function testContinuedListsCannotFormACycle(): void
    {
        [$root, $document, $pdf] = $this->tree();
        $first = new StructureElement(new StructureRole(new NameType('L'), $pdf), identifier: new LiteralString('one'), attributes: $this->continuedAttributes('two'));
        $second = new StructureElement(new StructureRole(new NameType('L'), $pdf), identifier: new LiteralString('two'), attributes: $this->continuedAttributes('one'));
        $first->addReference($second);
        $second->addReference($first);
        $document->addKid($first)->addKid($second);
        $findings = (new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse);
        self::assertStringContainsString('cycle', implode(' ', array_map(static fn ($finding): string => $finding->message, $findings)));
    }

    public function testDeclaredMathMlFormulaAttachmentNeedsSupplementRelationship(): void
    {
        foreach (['Data', 'Supplement'] as $relationship) {
            [$root, $document, $pdf] = $this->tree();
            $xml = new \PDF\Syntax\Object\StreamObject('<math/>', new DictionaryType(new Entry(new NameType('Subtype'), new NameType('application/mathml+xml'))));
            $file = new DictionaryType(new Entry(new NameType('AFRelationship'), new NameType($relationship)), new Entry(new NameType('EF'), new DictionaryType(new Entry(new NameType('F'), new IndirectReference($xml)))));
            $formula = new StructureElement(new StructureRole(new NameType('Formula'), $pdf), entries: new Entry(new NameType('AF'), new ArrayType(new IndirectReference($file))));
            $document->addKid($formula);
            self::assertSame($relationship === 'Data' ? ['8.2.5.29.1'] : [], $this->structureClauses($root));
        }
    }

    public function testWidgetWithoutLabelCannotUseTuAloneInsteadOfContents(): void
    {
        foreach (['unlabelled', 'labelled', 'described'] as $case) {
            [$catalog, $root] = $this->annotation('Widget', 'Form', $case === 'described' ? new IndirectReference(new TextString('Enter name')) : null, $case === 'labelled');
            $findings = (new WtPdfGraphChecks())->inspect($catalog, $root, WtPdfConformanceLevel::Accessibility);
            self::assertSame($case === 'unlabelled', in_array('8.10.2.3', $this->clauses($findings), true));
        }
    }

    public function testFreeTextDisplayedContentsIsNotAnAlternativeDescription(): void
    {
        foreach (['Text', 'FreeText'] as $subtype) {
            [$catalog, $root] = $this->annotation($subtype, 'Annot', new IndirectReference(new TextString('Displayed content')), false, new TextString('Alternative content'));
            $findings = (new WtPdfGraphChecks())->inspect($catalog, $root, WtPdfConformanceLevel::Accessibility);
            self::assertSame($subtype === 'Text', in_array('8.9.4.2', $this->clauses($findings), true));
            self::assertNotContains('8.9.3.3', $this->clauses($findings), 'Indirect Tabs is a valid PDF value.');
        }
    }

    public function testOnlyActualAppearanceStreamOwnershipTriggersAppearanceRestriction(): void
    {
        foreach ([false, true] as $appearance) {
            $page = new \PDF\Syntax\DocumentStructure\PageTree\PageObject(new \PDF\Syntax\DataStructure\Rectangle(new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(200), new \PDF\Syntax\Object\IntegerType(200)), new \PDF\Document\ResourceDictionary());
            $pages = (new PageTreeNode())->addKid($page);
            $stream = new \PDF\Syntax\Object\StreamObject(new \PDF\Syntax\ContentStreamsResources\ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties(new NameType('Span'), new DictionaryType(new Entry(new NameType('MCID'), new \PDF\Syntax\Object\IntegerType(0)))), new \PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent()), new DictionaryType(new Entry(new NameType('StructParents'), new \PDF\Syntax\Object\IntegerType(0))));
            $owner = new DictionaryType(new Entry(new NameType('Subtype'), new NameType($appearance ? 'Text' : 'Form')));
            if ($appearance) {
                $owner = $owner->with(new Entry(new NameType('AP'), new DictionaryType(new Entry(new NameType('N'), new IndirectReference($stream)))));
            }
            $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
            $root = new StructureTreeRoot($pages, namespaces: new StructureNamespaces($pdf), inclusionRules: new StructureInclusionRules());
            $span = $this->element('Span', $pdf)->addKid(new \PDF\Document\Structure\MarkedContentReference(new \PDF\Document\Structure\MarkedContentContainer($page, new \PDF\Syntax\Object\IntegerType(0), $stream, $owner), new \PDF\Document\Structure\MarkedContentIdentifier(new \PDF\Syntax\Object\IntegerType(0))));
            $root->addKid($this->element('Document', $pdf)->addKid($this->element('P', $pdf)->addKid($span)));
            $catalog = new \PDF\Syntax\DocumentStructure\DocumentCatalog($pages, ...$root->catalogEntries(new \PDF\Document\Structure\MarkInformation(new BooleanType(true))));
            self::assertSame($appearance, in_array('8.9.2.1', $this->clauses((new WtPdfGraphChecks())->inspect($catalog, $root, WtPdfConformanceLevel::Reuse)), true));
        }
    }

    public function testRenderedFontsInAnnotationAppearancesAndSelectedPatternsAreInspected(): void
    {
        foreach (['appearance', 'pattern'] as $case) {
            $font = new \PDF\Document\Fonts\Type1Font(\PDF\Document\Fonts\StandardFont::Helvetica, new Entry(new NameType('Encoding'), new NameType('WinAnsiEncoding')));
            $fontResources = new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
            $text = new \PDF\Syntax\ContentStreamsResources\ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject(), new \PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize(new NameType('F1'), new \PDF\Syntax\Object\IntegerType(12)), new \PDF\Syntax\ContentStreamsResources\Operators\ShowText(new LiteralString('A')), new \PDF\Syntax\ContentStreamsResources\Operators\EndTextObject());
            $stream = new \PDF\Syntax\Object\StreamObject($text, new DictionaryType(new Entry(new NameType('Resources'), $fontResources), new Entry(new NameType('PatternType'), new \PDF\Syntax\Object\IntegerType(1))));
            $resources = $case === 'pattern' ? new \PDF\Document\ResourceDictionary(new Entry(new NameType('Pattern'), new DictionaryType(new Entry(new NameType('P1'), new IndirectReference($stream))))) : new \PDF\Document\ResourceDictionary();
            $content = $case === 'pattern' ? new \PDF\Syntax\Object\StreamObject(new \PDF\Syntax\ContentStreamsResources\ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingColorWithPattern(new NameType('P1')))) : null;
            $entries = $case === 'appearance' ? [new Entry(new NameType('Annots'), new ArrayType(new IndirectReference(new DictionaryType(new Entry(new NameType('AP'), new DictionaryType(new Entry(new NameType('N'), new IndirectReference($stream))))))))] : [];
            $page = new \PDF\Syntax\DocumentStructure\PageTree\PageObject(new \PDF\Syntax\DataStructure\Rectangle(new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(200), new \PDF\Syntax\Object\IntegerType(200)), $resources, $content, ...$entries);
            $catalog = new \PDF\Syntax\DocumentStructure\DocumentCatalog((new PageTreeNode())->addKid($page));
            self::assertContains('8.4.5.5.1', $this->clauses((new \PDF\Document\Structure\WtPdfFontUsageChecks())->inspect($catalog)), $case);
        }
    }

    /** @return array{DictionaryType, StructureTreeRoot} */
    private function annotation(string $subtype, string $parentRole, ?\PDF\Syntax\FileStructure\BasicType $contents = null, bool $labelled = false, ?TextString $alt = null): array
    {
        $entries = $contents === null ? [] : [new Entry(new NameType('Contents'), $contents)];
        $object = new DictionaryType(new Entry(new NameType('Type'), new NameType('Annot')), new Entry(new NameType('Subtype'), new NameType($subtype)), new Entry(new NameType('StructParent'), new \PDF\Syntax\Object\IntegerType(0)), new Entry(new NameType('TU'), new TextString('TU is insufficient')), ...$entries);
        $page = new \PDF\Syntax\DocumentStructure\PageTree\PageObject(new \PDF\Syntax\DataStructure\Rectangle(new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(200), new \PDF\Syntax\Object\IntegerType(200)), new \PDF\Document\ResourceDictionary(), null, new Entry(new NameType('Annots'), new ArrayType(new IndirectReference($object))), new Entry(new NameType('Tabs'), new IndirectReference(new NameType('S'))));
        $pages = (new PageTreeNode())->addKid($page);
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $root = new StructureTreeRoot($pages, namespaces: new StructureNamespaces($pdf), inclusionRules: new StructureInclusionRules());
        $parent = $alt === null ? new StructureElement(new StructureRole(new NameType($parentRole), $pdf)) : new StructureElement(new StructureRole(new NameType($parentRole), $pdf), entries: new Entry(new NameType('Alt'), $alt));
        if ($labelled) {
            $parent->addKid($this->element('Lbl', $pdf));
        }
        $parent->addKid(new \PDF\Document\Structure\ObjectReference($page, $object, new \PDF\Syntax\Object\IntegerType(0)));
        $root->addKid($this->element('Document', $pdf)->addKid($parent));
        $catalog = new \PDF\Syntax\DocumentStructure\DocumentCatalog($pages, ...$root->catalogEntries(new \PDF\Document\Structure\MarkInformation(new BooleanType(true))));
        return [$catalog, $root];
    }

    private function privateUseText(string $case): DictionaryType
    {
        $code = new \PDF\Document\Fonts\CMaps\CMapCode(new \PDF\Syntax\Object\StringObject\HexadecimalString('A'));
        $spaces = new \PDF\Document\Fonts\CMaps\CodeSpaces(new \PDF\Document\Fonts\CMaps\CodeSpaceRange($code, $code));
        $unicode = new \PDF\Document\Fonts\ToUnicodeCMap(new \PDF\Document\Fonts\CMaps\UnicodeCMap(new \PDF\Document\Fonts\CMaps\CMapName(new NameType('PrivateUse')), $spaces, new \PDF\Document\Fonts\CMaps\UnicodeMappings(new \PDF\Document\Fonts\CMaps\UnicodeChar($code, (new \PDF\Document\Fonts\CMaps\UnicodeScalar(new \PDF\Syntax\Object\IntegerType(0xE000)))->sequence()))));
        $font = new DictionaryType(new Entry(new NameType('Type'), new NameType('Font')), new Entry(new NameType('Subtype'), new NameType('Type1')), new Entry(new NameType('Encoding'), new NameType('WinAnsiEncoding')), new Entry(new NameType('ToUnicode'), new IndirectReference($unicode)));
        $resources = new \PDF\Document\ResourceDictionary(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
        $property = new DictionaryType();
        if ($case === 'ancestor-alternative') {
            $property = $property->with(new Entry(new NameType('MCID'), new \PDF\Syntax\Object\IntegerType(0)));
        }
        if ($case === 'ActualText' || $case === 'private-alternative') {
            $property = $property->with(new Entry(new NameType('ActualText'), new TextString($case === 'ActualText' ? 'Symbol' : "\u{E001}")));
        }
        $content = new \PDF\Syntax\Object\StreamObject(new \PDF\Syntax\ContentStreamsResources\ContentStreams(
            new \PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties(new NameType($case === 'Artifact' ? 'Artifact' : 'Span'), $property),
            new \PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject(),
            new \PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize(new NameType('F1'), new \PDF\Syntax\Object\IntegerType(12)),
            new \PDF\Syntax\ContentStreamsResources\Operators\SetTextRenderingMode(\PDF\Text\TextRenderingMode::Invisible),
            new \PDF\Syntax\ContentStreamsResources\Operators\ShowText(new LiteralString('A')),
            new \PDF\Syntax\ContentStreamsResources\Operators\EndTextObject(),
            new \PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent(),
        ));
        $entries = $case === 'ancestor-alternative' ? [new Entry(new NameType('StructParents'), new \PDF\Syntax\Object\IntegerType(0))] : [];
        $page = new \PDF\Syntax\DocumentStructure\PageTree\PageObject(new \PDF\Syntax\DataStructure\Rectangle(new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(0), new \PDF\Syntax\Object\IntegerType(200), new \PDF\Syntax\Object\IntegerType(200)), $resources, $content, ...$entries);
        $pages = (new PageTreeNode())->addKid($page);
        if ($case === 'ancestor-alternative') {
            $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
            $root = new StructureTreeRoot($pages, namespaces: new StructureNamespaces($pdf), inclusionRules: new StructureInclusionRules());
            $document = $this->element('Document', $pdf);
            $paragraph = new StructureElement(new StructureRole(new NameType('P'), $pdf), entries: new Entry(new NameType('Alt'), new TextString('Symbol')));
            $span = new StructureElement(new StructureRole(new NameType('Span'), $pdf), page: $page);
            $span->addKid(new \PDF\Document\Structure\PageMarkedContent(new \PDF\Document\Structure\MarkedContentContainer($page, new \PDF\Syntax\Object\IntegerType(0)), new \PDF\Document\Structure\MarkedContentIdentifier(new \PDF\Syntax\Object\IntegerType(0))));
            $root->addKid($document->addKid($paragraph->addKid($span)));
            return new \PDF\Syntax\DocumentStructure\DocumentCatalog($pages, ...$root->catalogEntries(new \PDF\Document\Structure\MarkInformation(new BooleanType(true))));
        }
        return new \PDF\Syntax\DocumentStructure\DocumentCatalog($pages);
    }

    /** @return array{StructureTreeRoot, StructureElement, StructureElement} */
    private function continued(): array
    {
        [$root, $document, $pdf] = $this->tree();
        $first = new StructureElement(new StructureRole(new NameType('L'), $pdf), identifier: new LiteralString('first'));
        $second = $this->element('L', $pdf, $this->continuedAttributes('first'));
        $document->addKid($first)->addKid($second);
        return [$root, $first, $second];
    }

    private function continuedAttributes(string $id): StructureAttributes
    {
        return new StructureAttributes(new AttributeAssociation(new StructureAttributeObject(new DictionaryType(new Entry(new NameType('O'), new NameType('List')), new Entry(new NameType('ContinuedList'), new BooleanType(true)), new Entry(new NameType('ContinuedFrom'), new LiteralString($id))))));
    }

    /** @return array{StructureTreeRoot, StructureElement, StructureNamespace} */
    private function tree(): array
    {
        $pdf = new StructureNamespace(StandardStructureNamespace::PDF20);
        $root = new StructureTreeRoot(new PageTreeNode(), namespaces: new StructureNamespaces($pdf), inclusionRules: new StructureInclusionRules());
        $root->addKid($document = $this->element('Document', $pdf));
        return [$root, $document, $pdf];
    }

    private function element(string $name, StructureNamespace $pdf, ?StructureAttributes $attributes = null): StructureElement
    {
        return new StructureElement(new StructureRole(new NameType($name), $pdf), attributes: $attributes);
    }

    private function attributes(string $owner, string $key, \PDF\Syntax\FileStructure\BasicType $value): StructureAttributes
    {
        return new StructureAttributes(new AttributeAssociation(new StructureAttributeObject(new DictionaryType(new Entry(new NameType('O'), new NameType($owner)), new Entry(new NameType($key), $value)))));
    }

    /** @return list<string> */
    private function structureClauses(StructureTreeRoot $root): array
    {
        return $this->clauses((new WtPdfStructureChecks())->inspect($root, WtPdfConformanceLevel::Reuse));
    }

    /**
     * @param list<\PDF\Document\Structure\WtPdfFinding> $findings
     * @return list<string>
     */
    private function clauses(array $findings): array
    {
        return array_values(array_unique(array_map(static fn ($finding): string => $finding->clause, $findings)));
    }
}
