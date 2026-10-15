<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\DocumentGraphContext;
use PDF\Document\Fonts\Pdf20FontGraphValidation;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Pdf20FontGraphValidationTest extends TestCase
{
    /** @return iterable<string,array{StandardFont}> */
    public static function standardFonts(): iterable
    {
        foreach (StandardFont::cases() as $font) {
            yield $font->name => [$font];
        }
    }

    #[DataProvider('standardFonts')]
    public function testNativeStandardFontsRetainThePdf17ExceptionButRequireMetricsInPdf20(StandardFont $font): void
    {
        $native = new Type1Font($font);
        $native->validateVersion(new EffectivePdfVersion(new FileHeader('1.7'), new DictionaryType()));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PDF 2.0 simple fonts require explicit metrics');
        $native->validateVersion(new EffectivePdfVersion(new FileHeader('2.0'), new DictionaryType()));
    }

    /** @return iterable<string,array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testExplicitCourierMetricsAreWrittenAndPreservedWithoutImplicitEmbedding(CrossReferenceFormat $format): void
    {
        $font = require __DIR__ . '/../examples/fixtures/pdf20-courier-font.php';
        self::assertInstanceOf(Type1Font::class, $font);
        $font->validateVersion(new EffectivePdfVersion(new FileHeader('2.0'), new DictionaryType()));
        [$revision] = $this->document($font, '2.0', $format);
        $descriptor = $this->dictionary($revision, $font)->get('FontDescriptor');
        self::assertInstanceOf(IndirectReference::class, $descriptor);
        $frozen = $this->dictionary($revision, $descriptor->target());
        self::assertFalse($frozen->has('FontFile'));
        self::assertFalse($frozen->has('FontFile2'));
        $name = $frozen->get('FontName');
        self::assertInstanceOf(NameType::class, $name);
        self::assertSame('Courier', $name->value);
        $next = (new IncrementalUpdate($revision, new FileBody()))->toRevision();
        self::assertTrue(str_starts_with($next->bytes, $revision->bytes));
    }

    /** @return iterable<string,array{string,?BasicType}> */
    public static function malformedMetricEntries(): iterable
    {
        yield 'missing FirstChar' => ['FirstChar', null];
        yield 'null LastChar' => ['LastChar', new NullType()];
        yield 'real byte code' => ['FirstChar', new NumericType(65.0)];
        yield 'negative byte code' => ['FirstChar', new IntegerType(-1)];
        yield 'byte overflow' => ['LastChar', new IntegerType(256)];
        yield 'reversed range' => ['LastChar', new IntegerType(64)];
        yield 'missing width' => ['Widths', new ArrayType()];
        yield 'surplus width' => ['Widths', new ArrayType(new IntegerType(600), new IntegerType(600))];
        yield 'nonnumeric width' => ['Widths', new ArrayType(new NameType('width'))];
        yield 'indirect nonnumeric width' => ['Widths', new ArrayType(new IndirectReference(new NameType('width')))];
        yield 'missing descriptor' => ['FontDescriptor', null];
        yield 'wrong descriptor kind' => ['FontDescriptor', new IndirectReference(new ArrayType())];
        yield 'stream descriptor' => ['FontDescriptor', new IndirectReference(new StreamObject(''))];
        yield 'missing BaseFont' => ['BaseFont', null];
        yield 'invalid BaseFont' => ['BaseFont', new LiteralString('Courier')];
    }

    #[DataProvider('malformedMetricEntries')]
    public function testRawMetricEntriesCannotBypassPdf20Relationships(string $key, ?BasicType $value): void
    {
        $font = $this->font();
        $font = $value === null ? $font->without($key) : $font->with($this->entry($key, $value));
        $this->expectException(\InvalidArgumentException::class);
        $this->validate($font);
    }

    /** @return iterable<string,array{string,?BasicType}> */
    public static function malformedDescriptorEntries(): iterable
    {
        yield 'missing Type' => ['Type', null];
        yield 'wrong Type' => ['Type', new NameType('Font')];
        yield 'mismatched FontName' => ['FontName', new NameType('Helvetica')];
        yield 'missing Flags' => ['Flags', null];
        yield 'contradictory Flags' => ['Flags', new IntegerType(4 | 32)];
        yield 'reserved Flags' => ['Flags', new IntegerType(32 | 128)];
        yield 'missing ItalicAngle' => ['ItalicAngle', null];
        yield 'invalid ItalicAngle' => ['ItalicAngle', new NameType('upright')];
        yield 'missing FontBBox' => ['FontBBox', null];
        yield 'short FontBBox' => ['FontBBox', new ArrayType(new IntegerType(0))];
        yield 'invalid FontBBox coordinate' => ['FontBBox', new ArrayType(new IntegerType(0), new IntegerType(0), new NameType('x'), new IntegerType(10))];
        yield 'missing Ascent' => ['Ascent', null];
        yield 'negative Ascent' => ['Ascent', new IntegerType(-1)];
        yield 'missing Descent' => ['Descent', null];
        yield 'positive Descent' => ['Descent', new IntegerType(1)];
        yield 'missing StemV' => ['StemV', null];
        yield 'negative StemV' => ['StemV', new IntegerType(-1)];
        yield 'missing Latin CapHeight' => ['CapHeight', null];
    }

    #[DataProvider('malformedDescriptorEntries')]
    public function testRawDescriptorsCannotBypassTheirRequiredMetrics(string $key, ?BasicType $value): void
    {
        $descriptor = $this->descriptor();
        $descriptor = $value === null ? $descriptor->without($key) : $descriptor->with($this->entry($key, $value));
        $this->expectException(\InvalidArgumentException::class);
        $this->validate($this->font($descriptor));
    }

    public function testFontDescriptorMustRemainIndirectAndWidthsMayBeIndirect(): void
    {
        $font = $this->font()->with($this->entry('FirstChar', new IndirectReference(new IntegerType(65))))->with($this->entry('LastChar', new IndirectReference(new IntegerType(65))))->with($this->entry('Widths', new IndirectReference(new ArrayType(new IndirectReference(new NumericType(600.0))))));
        $this->validate($font);
        self::assertInstanceOf(IndirectReference::class, $font->get('Widths'));
        $this->expectException(\InvalidArgumentException::class);
        $this->validate($font->with($this->entry('FontDescriptor', $this->descriptor())));
    }

    public function testType3DescriptorExceptionUsesTheEffectiveIndirectTaggedFlag(): void
    {
        $font = $this->font()->without('BaseFont')->without('FontDescriptor')->with($this->entry('Subtype', new NameType('Type3')));
        $this->validate($font);
        $catalog = new DictionaryType($this->entry('MarkInfo', new IndirectReference(new DictionaryType($this->entry('Marked', new IndirectReference(new BooleanType(true)))))));
        $descriptor = new DictionaryType($this->entry('Type', new NameType('FontDescriptor')), $this->entry('FontName', new NameType('Type3Demo')), $this->entry('Flags', new IntegerType(4)), $this->entry('ItalicAngle', new IntegerType(0)));
        $this->validate($font->with($this->entry('FontDescriptor', new IndirectReference($descriptor))), $catalog);
        self::assertFalse($descriptor->has('FontBBox'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type3 may omit it only outside Tagged PDF');
        $this->validate($font, $catalog);
    }

    public function testRawType3DescriptorsMayBeUnnamedWhileOtherFontsRequireNames(): void
    {
        $descriptor = $this->descriptor()->without('FontName');
        $font = $this->font($descriptor)->without('BaseFont')->with($this->entry('Subtype', new NameType('Type3')));
        $this->validate($font);
        self::assertFalse($descriptor->has('FontName'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FontName for non-Type3');
        $this->validate($this->font($descriptor));
    }

    #[DataProvider('formats')]
    public function testRetainedType3NameAndDescriptorMustContinueToAgree(CrossReferenceFormat $format): void
    {
        $descriptor = $this->descriptor()->with($this->entry('FontName', new NameType('Shapes')));
        $font = $this->font($descriptor)->without('BaseFont')->with($this->entry('Subtype', new NameType('Type3')))->with($this->entry('Name', new NameType('Shapes')));
        [$revision] = $this->document($font, '2.0', $format);
        $replacement = $this->dictionary($revision, $descriptor)->with($this->entry('FontName', new NameType('Other')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type3 Name');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($replacement, $revision->binding($descriptor)->identifier))))->toRevision();
    }

    public function testPrivateResourceSpellingDoesNotCreateFontSemantics(): void
    {
        $private = new DictionaryType($this->entry('Resources', new DictionaryType($this->entry('Font', new LiteralString('private application data')))));
        $this->validate($private);
        $this->validate($private->with($this->entry('Subtype', new NameType('Form'))));
        self::assertCount(1, $private);
    }

    public function testAnUnknownRepertoireIsNotInferredFromTheNonsymbolicFlag(): void
    {
        $descriptor = $this->descriptor()->without('CapHeight')->with($this->entry('FontName', new NameType('CustomNonLatin')));
        $this->validate($this->font($descriptor)->with($this->entry('BaseFont', new NameType('CustomNonLatin'))));
        self::assertFalse($descriptor->has('CapHeight'));
    }

    public function testFontResourcesRequireTheFontTypeEvenWhenTheDictionaryHasNoType(): void
    {
        $font = $this->font()->without('Type');
        $page = new DictionaryType($this->entry('Type', new NameType('Page')), $this->entry('Resources', $this->resources($font)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type Font');
        $this->validate($page);
    }

    public function testFormStreamFontResourcesAreValidatedWithoutAnOptionalXObjectType(): void
    {
        $form = new StreamObject('', new DictionaryType($this->entry('Subtype', new NameType('Form')), $this->entry('Resources', $this->resources($this->font()->without('Widths')))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('metric range');
        $this->validate($form);
    }

    #[DataProvider('formats')]
    public function testUpgradingPdf17CannotRetainAStandardFontWithoutMetrics(CrossReferenceFormat $format): void
    {
        $font = new DictionaryType($this->entry('Type', new NameType('Font')), $this->entry('Subtype', new NameType('Type1')), $this->entry('BaseFont', new NameType('Helvetica')));
        [$revision, $catalog] = $this->document($font, '1.7', $format);
        $replacement = $this->dictionary($revision, $catalog)->with($this->entry('Version', new NameType('2.0')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('metric range');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($replacement, $revision->binding($catalog)->identifier))))->toRevision();
    }

    #[DataProvider('formats')]
    public function testExplicitFontReplacementCanUpgradeAnExistingPdf17Graph(CrossReferenceFormat $format): void
    {
        $font = new DictionaryType($this->entry('Type', new NameType('Font')), $this->entry('Subtype', new NameType('Type1')), $this->entry('BaseFont', new NameType('Courier')));
        [$revision, $catalog] = $this->document($font, '1.7', $format);
        $replacement = $this->dictionary($revision, $catalog)->with($this->entry('Version', new NameType('2.0')));
        $fixedFont = $this->font();
        $updated = (new IncrementalUpdate($revision, new FileBody(new IndirectObject($replacement, $revision->binding($catalog)->identifier), new IndirectObject($fixedFont, $revision->binding($font)->identifier))))->toRevision();
        self::assertTrue(str_starts_with($updated->bytes, $revision->bytes));
        $version = $this->dictionary($updated, $catalog)->get('Version');
        self::assertInstanceOf(NameType::class, $version);
        self::assertSame('2.0', $version->value);
        $next = (new IncrementalUpdate($updated, new FileBody()))->toRevision();
        self::assertTrue(str_starts_with($next->bytes, $updated->bytes));
    }

    #[DataProvider('formats')]
    public function testRetainedFontUsesTheReplacementDescriptorInTheEffectiveRevision(CrossReferenceFormat $format): void
    {
        $descriptor = $this->descriptor();
        $font = $this->font($descriptor);
        [$revision] = $this->document($font, '2.0', $format);
        $invalid = $descriptor->without('Ascent');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('numeric Ascent');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($invalid, $revision->binding($descriptor)->identifier))))->toRevision();
    }

    #[DataProvider('formats')]
    public function testRetainedType3FontRequiresADescriptorWhenTheNewRevisionBecomesTagged(CrossReferenceFormat $format): void
    {
        $font = $this->font()->without('BaseFont')->without('FontDescriptor')->with($this->entry('Subtype', new NameType('Type3')));
        [$revision, $catalog] = $this->document($font, '2.0', $format);
        $markInfo = new DictionaryType($this->entry('Marked', new IndirectReference(new BooleanType(true))));
        $updated = $this->dictionary($revision, $catalog)->with($this->entry('MarkInfo', new IndirectReference($markInfo)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type3 may omit it only outside Tagged PDF');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($updated, $revision->binding($catalog)->identifier))))->toRevision();
    }

    /** @return array{FileRevision,DocumentCatalog} */
    private function document(DictionaryType $font, string $version, CrossReferenceFormat $format): array
    {
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(100)), $this->resources($font), new StreamObject('BT /F1 12 Tf 20 50 Td (A) Tj ET'));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        return [(new FileStructure(new FileHeader($version), new FileBody($catalog), crossReferenceFormat: $format))->toRevision(), $catalog];
    }

    private function resources(DictionaryType $font): ResourceDictionary
    {
        return new ResourceDictionary($this->entry('Font', new DictionaryType($this->entry('F1', new IndirectReference($font)))));
    }

    private function dictionary(FileRevision $revision, BasicType $object): DictionaryType
    {
        return $revision->binding($object)->dictionary ?? throw new \LogicException('The emitted fixture must retain its dictionary.');
    }

    private function validate(DictionaryType|StreamObject $value, DictionaryType $catalog = new DictionaryType()): void
    {
        $catalog = $catalog->with($this->entry('Subject', new IndirectReference($value)));
        $context = new SerializationContext();
        $context->prepare($catalog);
        $graph = new DocumentGraphContext($context, $catalog);
        (new Pdf20FontGraphValidation())->validate($value, $graph->dictionary($value) ?? throw new \LogicException('Fixture dictionary is required.'), $graph, new EffectivePdfVersion(new FileHeader('2.0'), $catalog));
    }

    private function font(?DictionaryType $descriptor = null): DictionaryType
    {
        return new DictionaryType($this->entry('Type', new NameType('Font')), $this->entry('Subtype', new NameType('Type1')), $this->entry('BaseFont', new NameType('Courier')), $this->entry('FirstChar', new IntegerType(65)), $this->entry('LastChar', new IntegerType(65)), $this->entry('Widths', new ArrayType(new IntegerType(600))), $this->entry('FontDescriptor', new IndirectReference($descriptor ?? $this->descriptor())));
    }

    private function descriptor(): DictionaryType
    {
        return new DictionaryType($this->entry('Type', new NameType('FontDescriptor')), $this->entry('FontName', new NameType('Courier')), $this->entry('Flags', new IntegerType(33)), $this->entry('FontBBox', new ArrayType(new IntegerType(-23), new IntegerType(-250), new IntegerType(715), new IntegerType(805))), $this->entry('ItalicAngle', new IntegerType(0)), $this->entry('Ascent', new IntegerType(629)), $this->entry('Descent', new IntegerType(-157)), $this->entry('CapHeight', new IntegerType(562)), $this->entry('StemV', new IntegerType(51)));
    }

    private function entry(string $name, BasicType $value): Entry
    {
        return new Entry(new NameType($name), $value);
    }
}
