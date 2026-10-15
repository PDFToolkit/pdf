<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Fonts\Encodings\EncodingDifference;
use PDF\Document\Fonts\Encodings\EncodingDifferences;
use PDF\Document\Fonts\Encodings\EncodingDictionary;
use PDF\Document\Fonts\SimpleWidths;
use PDF\Document\Fonts\Type3CharProc;
use PDF\Document\Fonts\Type3CharProcs;
use PDF\Document\Fonts\Type3Font;
use PDF\Document\Fonts\Type3Glyph;
use PDF\Document\Fonts\Type3GlyphSelection;
use PDF\Document\ResourceDictionary;
use PDF\Document\Structure\WtPdfFinding;
use PDF\Document\Structure\WtPdfFindingStatus;
use PDF\Document\Structure\WtPdfFontUsageChecks;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\ContentStreamsResources\Type3GlyphContentStream;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Text\TextRenderingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WtPdfType3UsageTest extends TestCase
{
    public function testSelectionUsesActualBytesAndOnlyDistinctSelectedCharProcsInOrder(): void
    {
        $first = $this->glyph();
        $second = $this->glyph();
        $font = $this->font($first, $second);
        $selection = new Type3GlyphSelection($font, new LiteralString("BABA\0C\xffC\0"));
        self::assertSame([$second, $first], $selection->glyphs);
        self::assertSame([0, 67, 255], array_map(static fn (IntegerType $code): int => $code->integer(), $selection->undefinedCodes));
        self::assertSame([], (new Type3GlyphSelection($font, new LiteralString('')))->glyphs);
    }

    public function testSelectionByteBudgetIsCheckedBeforeScanning(): void
    {
        $this->expectException(\LengthException::class);
        new Type3GlyphSelection($this->font($this->glyph(), $this->glyph()), new LiteralString('AA'), 1);
    }

    public function testSelectedAuxiliaryFontNeedsEmbeddingButAnUnusedCharProcDoesNot(): void
    {
        $font = $this->font($this->glyph(...$this->text('Aux', 'x')->operators), $this->glyph(), $this->resources('Aux', $this->auxiliary(false)));
        $checks = new WtPdfFontUsageChecks();
        self::assertContains('8.4.5.5.1', $this->failures($checks->inspect($this->catalog($font, 'A'))));
        self::assertSame([], $this->failures($checks->inspect($this->catalog($font, 'BBBB'))));
        $messages = array_map(static fn (WtPdfFinding $finding): string => $finding->message, $checks->inspect($this->catalog($font, 'B')));
        self::assertStringNotContainsString('selected Type3 CharProc', implode(' ', $messages));
    }

    public function testType3ResourcesOverridePageFontsAndOmittedResourcesInherit(): void
    {
        $glyph = $this->glyph(...$this->text('Aux', 'x')->operators);
        $pageResources = $this->resources('Aux', $this->auxiliary(false));
        $local = $this->font($glyph, $this->glyph(), $this->resources('Aux', $this->auxiliary(true)));
        $inherited = $this->font($glyph, $this->glyph());
        $checks = new WtPdfFontUsageChecks();
        self::assertSame([], $this->failures($checks->inspect($this->catalog($local, 'A', extraResources: $pageResources))));
        self::assertContains('8.4.5.5.1', $this->failures($checks->inspect($this->catalog($inherited, 'A', extraResources: $pageResources))));
    }

    public function testGlyphResourcesOverrideFontAndPageWithoutMerging(): void
    {
        $glyph = new Type3Glyph($this->glyph(...$this->text('Aux', 'x')->operators)->content, $this->resources('Aux', $this->auxiliary(true)));
        $font = $this->font($glyph, $this->glyph(), $this->resources('Aux', $this->auxiliary(false)));
        $checks = new WtPdfFontUsageChecks();
        self::assertSame([], $this->failures($checks->inspect($this->catalog($font, 'A', extraResources: $this->resources('Aux', $this->auxiliary(false))))));
        $empty = new Type3Glyph($glyph->content, new DictionaryType());
        $missing = $checks->inspect($this->catalog($this->font($empty, $this->glyph(), $this->resources('Aux', $this->auxiliary(true))), 'A'));
        self::assertNotSame([], $this->failures($missing));
    }

    public function testUnembeddedGlyphLocalAuxiliaryCannotBorrowEmbeddedParentResource(): void
    {
        $glyph = new Type3Glyph($this->glyph(...$this->text('Aux', 'x')->operators)->content, $this->resources('Aux', $this->auxiliary(false)));
        $font = $this->font($glyph, $this->glyph(), $this->resources('Aux', $this->auxiliary(true)));
        self::assertContains('8.4.5.5.1', $this->failures((new WtPdfFontUsageChecks())->inspect($this->catalog($font, 'A'))));
    }

    public function testGlyphInFormFallsBackToTheInheritedPageNotTheFormResources(): void
    {
        $font = $this->font($this->glyph(...$this->text('Aux', 'x')->operators), $this->glyph());
        $formFonts = $this->resources('Aux', $this->auxiliary(false))->get('Font')->with(new Entry(new NameType('Outer'), new IndirectReference($font)));
        $form = new StreamObject($this->text('Outer', 'A'), new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Form')), new Entry(new NameType('Resources'), new DictionaryType(new Entry(new NameType('Font'), $formFonts)))));
        $resources = $this->resources('Aux', $this->auxiliary(true))->with(new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Fm'), new IndirectReference($form)))));
        $pages = new PageTreeNode(new Entry(new NameType('Resources'), new ResourceDictionary(...iterator_to_array($resources->entries(), false))));
        $pages->addKid(new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), null, new StreamObject(new ContentStreams(new Op\InvokeXObject(new NameType('Fm'))))));
        self::assertSame([], $this->failures((new WtPdfFontUsageChecks())->inspect(new DocumentCatalog($pages))));
    }

    /** @return iterable<string,array{int,bool}> */
    public static function renderingModes(): iterable
    {
        foreach (range(0, 7) as $mode) {
            yield (string) $mode => [$mode, !in_array($mode, [3, 7], true)];
        }
    }

    #[DataProvider('renderingModes')]
    public function testType3ModesThreeAndSevenDoNotRenderAuxiliaryGlyphPrograms(int $mode, bool $rendered): void
    {
        $font = $this->font($this->glyph(...$this->text('Aux', 'x', 0)->operators), $this->glyph(), $this->resources('Aux', $this->auxiliary(false)));
        $failures = $this->failures((new WtPdfFontUsageChecks())->inspect($this->catalog($font, 'A', $mode)));
        self::assertSame($rendered, in_array('8.4.5.5.1', $failures, true));
    }

    public function testInvisibleTextInsideAnActuallyRenderedGlyphRetainsItsOwnEmbeddingException(): void
    {
        $font = $this->font($this->glyph(...$this->text('Aux', 'x', 3)->operators), $this->glyph(), $this->resources('Aux', $this->auxiliary(false)));
        self::assertSame([], $this->failures((new WtPdfFontUsageChecks())->inspect($this->catalog($font, 'A'))));
    }

    public function testNestedFormsInSelectedGlyphsUseTheirOwnFontResources(): void
    {
        $form = new StreamObject($this->text('Aux', 'x'), new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Form')), new Entry(new NameType('Resources'), $this->resources('Aux', $this->auxiliary(false)))));
        $resources = new DictionaryType(new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Nested'), new IndirectReference($form)))));
        $font = $this->font($this->glyph(new Op\InvokeXObject(new NameType('Nested'))), $this->glyph(), $resources);
        self::assertContains('8.4.5.5.1', $this->failures((new WtPdfFontUsageChecks())->inspect($this->catalog($font, 'A'))));
    }

    public function testUndefinedAndExplicitNotdefCodesAreFailuresEvenWithoutRendering(): void
    {
        $font = $this->font($this->glyph(), $this->glyph());
        self::assertContains('8.4.5.9', $this->failures((new WtPdfFontUsageChecks())->inspect($this->catalog($font, "\0C", 3))));
    }

    public function testRecursiveGlyphExecutionIsRejectedWithoutAnObjectGraphCycle(): void
    {
        $glyph = $this->glyph(new Op\BeginTextObject(), new Op\ShowText(new LiteralString('A')), new Op\EndTextObject());
        $font = $this->font($glyph, $this->glyph());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cycle');
        (new WtPdfFontUsageChecks())->inspect($this->catalog($font, 'A'));
    }

    public function testSelectedGlyphsShareTheExistingRecursionAndCumulativeTextBudgets(): void
    {
        $font = $this->font($this->glyph(...$this->text('Aux', 'xx')->operators), $this->glyph(), $this->resources('Aux', $this->auxiliary(true)));
        foreach ([new WtPdfFontUsageChecks(maximumDepth: 1), new WtPdfFontUsageChecks(maximumTextBytes: 2), new WtPdfFontUsageChecks(maximumOperations: 6)] as $checks) {
            try {
                $checks->inspect($this->catalog($font, 'A'));
                self::fail('Glyph execution cannot escape its enclosing inspection budgets.');
            } catch (\OverflowException $error) {
                self::assertStringContainsString('budget', $error->getMessage());
            }
        }
    }

    /** @param list<WtPdfFinding> $findings
     * @return list<string>
     */
    private function failures(array $findings): array
    {
        return array_values(array_map(static fn (WtPdfFinding $finding): string => $finding->clause, array_filter($findings, static fn (WtPdfFinding $finding): bool => $finding->status === WtPdfFindingStatus::Failed)));
    }

    private function glyph(\PDF\Syntax\ContentStreamsResources\Operator ...$operators): Type3Glyph
    {
        return new Type3Glyph(new Type3GlyphContentStream(new Op\SetGlyphWidth(new IntegerType(600), new IntegerType(0)), ...$operators));
    }

    private function font(Type3Glyph $first, Type3Glyph $second, ?DictionaryType $resources = null): Type3Font
    {
        return new Type3Font(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(600), new IntegerType(700)), new Matrix(a: new NumericType(0.001), d: new NumericType(0.001)), new Type3CharProcs(new Type3CharProc(new NameType('A'), $first), new Type3CharProc(new NameType('B'), $second)), new EncodingDictionary(new EncodingDifferences(new EncodingDifference(new IntegerType(65), new NameType('A'), new NameType('B'), new NameType('.notdef')))), new SimpleWidths(new IntegerType(65), new IntegerType(600), new IntegerType(600), new IntegerType(600)), $resources);
    }

    private function auxiliary(bool $embedded): DictionaryType
    {
        // Structural embedding evidence only: this audit does not validate a font program.
        $entries = $embedded ? [new Entry(new NameType('FontDescriptor'), new DictionaryType(new Entry(new NameType('FontFile'), new StreamObject('explicit test program'))))] : [];
        return new DictionaryType(new Entry(new NameType('Type'), new NameType('Font')), new Entry(new NameType('Subtype'), new NameType('Type1')), new Entry(new NameType('Encoding'), new NameType('WinAnsiEncoding')), ...$entries);
    }

    private function resources(string $name, DictionaryType $font): DictionaryType
    {
        return new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType($name), new IndirectReference($font)))));
    }

    private function text(string $font, string $text, int $mode = 0): ContentStreams
    {
        return new ContentStreams(new Op\BeginTextObject(), new Op\SetTextFontAndSize(new NameType($font), new IntegerType(12)), new Op\SetTextRenderingMode(TextRenderingMode::from($mode)), new Op\ShowText(new LiteralString($text)), new Op\EndTextObject());
    }

    private function catalog(Type3Font $font, string $text, int $mode = 0, ?DictionaryType $extraResources = null): DocumentCatalog
    {
        $fonts = $extraResources?->get('Font');
        $fonts = $fonts instanceof DictionaryType ? $fonts : new DictionaryType();
        $resources = new ResourceDictionary(new Entry(new NameType('Font'), $fonts->with(new Entry(new NameType('Outer'), new IndirectReference($font)))));
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), $resources, new StreamObject($this->text('Outer', $text, $mode)));
        return new DocumentCatalog((new PageTreeNode())->addKid($page));
    }
}
