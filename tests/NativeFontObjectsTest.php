<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Fonts\CIDClassDescriptor;
use PDF\Document\Fonts\CIDDefaultVerticalMetrics;
use PDF\Document\Fonts\CIDFontDescriptors;
use PDF\Document\Fonts\CIDFontMetricOverride;
use PDF\Document\Fonts\CIDFontType0;
use PDF\Document\Fonts\CIDFontType2;
use PDF\Document\Fonts\CIDGlyphClass;
use PDF\Document\Fonts\CIDGlyphIndex;
use PDF\Document\Fonts\CIDGlyphIndexMap;
use PDF\Document\Fonts\CIDIdentityGlyphMap;
use PDF\Document\Fonts\CIDMetrics;
use PDF\Document\Fonts\CIDRange;
use PDF\Document\Fonts\CIDSet;
use PDF\Document\Fonts\CIDVerticalMetric;
use PDF\Document\Fonts\CIDVerticalMetrics;
use PDF\Document\Fonts\CIDVerticalRange;
use PDF\Document\Fonts\CIDVerticalRun;
use PDF\Document\Fonts\CIDVerticalWidths;
use PDF\Document\Fonts\CIDWidthArray;
use PDF\Document\Fonts\CIDWidthRange;
use PDF\Document\Fonts\CIDWidthRun;
use PDF\Document\Fonts\CMaps\CMapCode;
use PDF\Document\Fonts\CMaps\CMapName;
use PDF\Document\Fonts\CMaps\CMapSystemInfo;
use PDF\Document\Fonts\CMaps\CodeSpaceRange;
use PDF\Document\Fonts\CMaps\CodeSpaces;
use PDF\Document\Fonts\CMaps\IdentityEncoding;
use PDF\Document\Fonts\CMaps\CMapWritingMode;
use PDF\Document\Fonts\CMaps\UnicodeChar;
use PDF\Document\Fonts\CMaps\UnicodeCMap;
use PDF\Document\Fonts\CMaps\UnicodeMappings;
use PDF\Document\Fonts\CMaps\UnicodeScalar;
use PDF\Document\Fonts\Encodings\EncodingDictionary;
use PDF\Document\Fonts\Encodings\EncodingDifference;
use PDF\Document\Fonts\Encodings\EncodingDifferences;
use PDF\Document\Fonts\Encodings\EncodingVector;
use PDF\Document\Fonts\Encodings\EncodingVectorKind;
use PDF\Document\Fonts\Encodings\NamedFontEncoding;
use PDF\Document\Fonts\Encodings\PredefinedEncoding;
use PDF\Document\Fonts\EmbeddedFontProgram;
use PDF\Document\Fonts\FontCharSet;
use PDF\Document\Fonts\FontDescriptor;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\Fonts\FontDescriptorOptions;
use PDF\Document\Fonts\FontFile;
use PDF\Document\Fonts\FontFile2;
use PDF\Document\Fonts\FontFile3;
use PDF\Document\Fonts\FontFile3Subtype;
use PDF\Document\Fonts\FontProgramMetadata;
use PDF\Document\Fonts\FontStream;
use PDF\Document\Fonts\FontStyle;
use PDF\Document\Fonts\FontSubsetRegistry;
use PDF\Document\Fonts\FontSubsetTag;
use PDF\Document\Fonts\FontSubtype;
use PDF\Document\Fonts\MMType1Font;
use PDF\Document\Fonts\OpenTypeOutline;
use PDF\Document\Fonts\Panose;
use PDF\Document\Fonts\SimpleFontMetrics;
use PDF\Document\Fonts\SimpleWidths;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\SubsetFontName;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Document\Fonts\TrueTypeFont;
use PDF\Document\Fonts\Type0Font;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Fonts\Type3CharProc;
use PDF\Document\Fonts\Type3CharProcs;
use PDF\Document\Fonts\Type3Font;
use PDF\Document\Fonts\Type3Glyph;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\Type3GlyphContentStream;
use PDF\Syntax\ContentStreamsResources\Operators\SetGlyphWidthAndBoundingBox;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\DataStructure\ByteString;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\StreamObject;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeFontObjectsTest extends TestCase
{
    private function file(DictionaryType ...$fonts): FileStructure
    {
        $entries = [];
        foreach ($fonts as $index => $font) {
            $entries[] = new Entry(new NameType('F' . ($index + 1)), new IndirectReference($font));
        }
        $resources = new ResourceDictionary(new Font(new DictionaryType(...$entries)));
        $pages = (new PageTreeNode())->addKid(new PageObject($this->rectangle(), $resources));
        $catalog = new DocumentCatalog($pages);
        return new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
    }

    private function rectangle(int $right = 600): Rectangle
    {
        return new Rectangle(new NumericType(0), new NumericType(0), new NumericType($right), new NumericType(700));
    }
    private function info(string $ordering = 'Identity'): CMapSystemInfo
    {
        return new CMapSystemInfo(new LiteralString('Adobe'), new LiteralString($ordering), new IntegerType(0));
    }
    private function descriptor((FontStream&EmbeddedFontProgram)|null $program, NameType|SubsetFontName $name = new NameType('Demo'), Entry|FontDescriptorOptions ...$entries): FontDescriptor
    {
        return new FontDescriptor($program, $name, new FontDescriptorFlags(32), $this->rectangle(), new NumericType(0), new NumericType(700), new NumericType(-200), new NumericType(700), new NumericType(80), ...$entries);
    }
    private function widths(int $first = 65, int $width = 500): SimpleWidths
    {
        return new SimpleWidths(new IntegerType($first), new NumericType($width));
    }
    private function glyph(int $width = 500): Type3Glyph
    {
        return new Type3Glyph(new Type3GlyphContentStream(new SetGlyphWidthAndBoundingBox(new NumericType($width), new NumericType(0), new NumericType(0), new NumericType(0), new NumericType(500), new NumericType(700))));
    }
    private function type3(int $width = 500, int $glyphWidth = 500, int $right = 600): Type3Font
    {
        return new Type3Font($this->rectangle($right), new Matrix(), new Type3CharProcs(new Type3CharProc(new NameType('A'), $this->glyph($glyphWidth))), new EncodingDictionary(new EncodingDifferences(new EncodingDifference(new IntegerType(65), new NameType('A')))), $this->widths(65, $width), new DictionaryType());
    }
    private function unicode(int $width = 1): ToUnicodeCMap
    {
        return new ToUnicodeCMap(new UnicodeCMap(
            new CMapName(new NameType('Test-UCS')),
            new CodeSpaces(new CodeSpaceRange(new CMapCode(new HexadecimalString(str_repeat("\0", $width))), new CMapCode(new HexadecimalString(str_repeat("\xff", $width))))),
            new UnicodeMappings(new UnicodeChar(new CMapCode(new HexadecimalString(str_repeat("\0", $width - 1) . 'A')), (new UnicodeScalar(new IntegerType(65)))->sequence()))
        ));
    }

    public function testType1SegmentsAndDecodedLengths(): void
    {
        $file = new FontFile(new AsciiString('clear'), new ByteString("\xff\x00"), new ByteString(str_repeat('0', 512) . "cleartomark\n"), new FlateDecode());
        self::assertSame(5, $file->dictionary()->get('Length1')->value);
        self::assertSame(2, $file->dictionary()->get('Length2')->value);
        self::assertSame(524, $file->dictionary()->get('Length3')->value);
        self::assertSame('clear' . "\xff\0" . str_repeat('0', 512) . "cleartomark\n", gzuncompress($file->bytes(new SerializationContext())));
        self::assertSame(0, (new FontFile(new AsciiString('clear'), new ByteString('binary')))->dictionary()->get('Length3')->value);
    }

    public function testBadFixedType1PortionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontFile(new AsciiString('clear'), new ByteString('binary'), new ByteString(str_repeat('0', 511) . 'cleartomark'));
    }

    /** @return iterable<string, array{FontFile3Subtype, ?OpenTypeOutline, FontSubtype}> */
    public static function validFile3Bindings(): iterable
    {
        yield 'compact Type1' => [FontFile3Subtype::Type1C, null, FontSubtype::Type1];
        yield 'compact CID' => [FontFile3Subtype::CIDFontType0C, null, FontSubtype::CIDFontType0];
        yield 'OpenType glyf simple' => [FontFile3Subtype::OpenType, OpenTypeOutline::Glyf, FontSubtype::TrueType];
        yield 'OpenType glyf CID' => [FontFile3Subtype::OpenType, OpenTypeOutline::Glyf, FontSubtype::CIDFontType2];
        yield 'OpenType CFF simple' => [FontFile3Subtype::OpenType, OpenTypeOutline::CffSimple, FontSubtype::Type1];
        yield 'OpenType CFF simple as CID' => [FontFile3Subtype::OpenType, OpenTypeOutline::CffSimple, FontSubtype::CIDFontType0];
        yield 'OpenType CFF CID' => [FontFile3Subtype::OpenType, OpenTypeOutline::CffCid, FontSubtype::CIDFontType0];
    }

    #[DataProvider('validFile3Bindings')]
    public function testAllFile3FormatsBindToTheirDeclaredFamily(FontFile3Subtype $format, ?OpenTypeOutline $outline, FontSubtype $subtype): void
    {
        $program = new FontFile3('normalized external program', $format, $outline);
        $descriptor = $this->descriptor($program);
        $descriptor->assertFor($subtype);
        self::assertInstanceOf(IndirectReference::class, $descriptor->get('FontFile3'));
        self::assertSame($format->value, $program->dictionary()->get('Subtype')->value);
        self::assertSame($outline === OpenTypeOutline::Glyf, $program->dictionary()->has('Length1'));
    }

    public function testOpenTypeOutlineDeclarationIsMandatory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontFile3('bytes', FontFile3Subtype::OpenType);
    }

    public function testCompactFontsHaveNoOpenTypeDeclaration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontFile3('bytes', FontFile3Subtype::Type1C, OpenTypeOutline::CffSimple);
    }

    public function testProgramCannotBeAttachedToAnotherFontFamily(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->descriptor(new FontFile2('bytes'))->assertFor(FontSubtype::Type1);
    }

    public function testSubsetTagCharsetAndRegistryBindToOneProgram(): void
    {
        $name = new SubsetFontName(new FontSubsetTag('ABCDEF'), new NameType('Demo'));
        $program = new FontFile3('bytes', FontFile3Subtype::Type1C);
        $charset = new FontCharSet(new NameType('A'), new NameType('B C'));
        self::assertSame('/A/B#20C', $charset->value);
        $descriptor = $this->descriptor($program, $name, new Entry(new NameType('CharSet'), $charset));
        $descriptor->assertFor(FontSubtype::Type1);
        self::assertSame('ABCDEF+Demo', $descriptor->fontName->value);
        $registry = new FontSubsetRegistry();
        $registry->register($name, $program);
        $registry->register($name, $program);
        (new FontSubsetRegistry())->register($name, new FontFile3('other document', FontFile3Subtype::Type1C));
        $this->expectException(\InvalidArgumentException::class);
        $registry->register($name, new FontFile3('different subset', FontFile3Subtype::Type1C));
    }

    public function testSubsetTagCannotContainDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontSubsetTag('ABC12F');
    }

    public function testCharsetOmitsNotdef(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontCharSet(new NameType('.notdef'));
    }

    public function testCidSetBitsIncludeHighCidWithoutEndiannessAmbiguity(): void
    {
        $set = new CIDSet([new IntegerType(0), new IntegerType(7), new IntegerType(8), new IntegerType(65535)]);
        self::assertSame(8192, strlen($set->data));
        self::assertSame("\x81\x80", substr($set->data, 0, 2));
        self::assertSame("\x01", substr($set->data, -1));
        self::assertTrue($set->contains(65535));
    }

    public function testCidSetRequiresNotdef(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CIDSet([new IntegerType(1)]);
    }

    public function testCidSubsetDescriptorStyleAndCollection(): void
    {
        $name = new SubsetFontName(new FontSubsetTag('ABCDEF'), new NameType('CJK'));
        $descriptor = $this->descriptor(
            new FontFile3('bytes', FontFile3Subtype::CIDFontType0C),
            $name,
            new Entry(new NameType('CIDSet'), new IndirectReference(new CIDSet([new IntegerType(0), new IntegerType(1)]))),
            new Entry(new NameType('Style'), new FontStyle(new Panose(str_repeat("\0", 12))))
        );
        $cid = new CIDFontType0($descriptor, $this->info('Japan1'));
        self::assertSame('ABCDEF+CJK', $cid->get('BaseFont')->value);
        self::assertSame('CIDFontType0', $cid->get('Subtype')->value);
        self::assertInstanceOf(Type0Font::class, new Type0Font($cid));
    }

    public function testPanoseIsTwelveBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Panose(str_repeat("\0", 10));
    }

    public function testDescriptorConditionalMetricsForType3AndNonLatinFonts(): void
    {
        $type3 = new FontDescriptor(null, new NameType('Shapes'), new FontDescriptorFlags(4), null, new NumericType(0), null, null, null, null);
        $type3->assertFor(FontSubtype::Type3);
        $nonLatin = new FontDescriptor(null, new NameType('CJK'), new FontDescriptorFlags(4), $this->rectangle(), new NumericType(0), new NumericType(700), new NumericType(-200), null, new NumericType(80), new FontDescriptorOptions(false));
        $nonLatin->assertFor(FontSubtype::CIDFontType0);
        self::assertFalse($nonLatin->has('CapHeight'));
        $this->expectException(\InvalidArgumentException::class);
        $type3->assertFor(FontSubtype::Type1);
    }

    public function testFontStretchAndWeightConditions(): void
    {
        $descriptor = $this->descriptor(null, new NameType('Demo'), new Entry(new NameType('FontWeight'), new NumericType(700.0)), new Entry(new NameType('FontStretch'), new NameType('Condensed')));
        self::assertSame(700.0, $descriptor->get('FontWeight')->value);
        $this->expectException(\InvalidArgumentException::class);
        $this->descriptor(null, new NameType('Demo'), new Entry(new NameType('FontWeight'), new NumericType(1001)));
    }

    public function testFdUsesActualCollectionClassesAndMetricOverrideInheritance(): void
    {
        $classes = new CIDFontDescriptors($this->info('Japan1'), new CIDClassDescriptor(CIDGlyphClass::HKana, new CIDFontMetricOverride(new Entry(new NameType('AvgWidth'), new NumericType(500)), new Entry(new NameType('Flags'), new IntegerType(3)))));
        $descriptor = $this->descriptor(null, new NameType('CJK'), new Entry(new NameType('FD'), $classes));
        new CIDFontType0($descriptor, $this->info('Japan1'));
        $effective = $classes->effective(CIDGlyphClass::HKana, $descriptor);
        self::assertSame(500, $effective->get('AvgWidth')->value);
        self::assertSame(700, $effective->get('Ascent')->value);
        $this->expectException(\InvalidArgumentException::class);
        new CIDFontType0($descriptor, $this->info('Korea1'));
    }

    public function testFdCannotIncludeFontPrograms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CIDFontMetricOverride(new Entry(new NameType('FontFile2'), new IndirectReference(new FontFile2('bytes'))));
    }

    public function testInvalidGlyphClassForCollectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CIDFontDescriptors($this->info('GB1'), new CIDClassDescriptor(CIDGlyphClass::Ruby, new CIDFontMetricOverride()));
    }

    public function testPredefinedVectorsPreserveTheSourceEncodingDistinctions(): void
    {
        $mac = new EncodingVector(EncodingVectorKind::MacRoman);
        $win = new EncodingVector(EncodingVectorKind::WinAnsi);
        $std = new EncodingVector(EncodingVectorKind::Standard);
        self::assertSame('currency', $mac->glyph(new IntegerType(219))->value);
        self::assertSame('Euro', $win->glyph(new IntegerType(128))->value);
        self::assertSame('bullet', $win->glyph(new IntegerType(129))->value);
        self::assertSame('hyphen', $win->glyph(new IntegerType(173))->value);
        self::assertSame('space', $win->glyph(new IntegerType(160))->value);
        self::assertSame('space', $mac->glyph(new IntegerType(202))->value);
        self::assertSame('quoteright', $std->glyph(new IntegerType(39))->value);
        self::assertSame('quotesingle', $win->glyph(new IntegerType(39))->value);
        self::assertSame('AEsmall', (new EncodingVector(EncodingVectorKind::MacExpert))->glyph(new IntegerType(190))->value);
        self::assertSame('Alpha', (new EncodingVector(EncodingVectorKind::Symbol))->glyph(new IntegerType(65))->value);
        self::assertSame('a1', (new EncodingVector(EncodingVectorKind::ZapfDingbats))->glyph(new IntegerType(33))->value);
        self::assertCount(2, $win->codes(new NameType('space')));
        self::assertCount(149, $std->glyphs);
        self::assertCount(208, $mac->glyphs);
        self::assertCount(224, $win->glyphs);
        self::assertCount(167, (new EncodingVector(EncodingVectorKind::MacExpert))->glyphs);
        self::assertCount(189, (new EncodingVector(EncodingVectorKind::Symbol))->glyphs);
        self::assertCount(189, (new EncodingVector(EncodingVectorKind::ZapfDingbats))->glyphs);
    }

    public function testDifferencesCompositionPreservesOrderedRunsAndBase(): void
    {
        $differences = new EncodingDifferences(new EncodingDifference(new IntegerType(200), new NameType('A'), new NameType('B')), new EncodingDifference(new IntegerType(39), new NameType('quotesingle')));
        self::assertSame('[200 /A /B 39 /quotesingle]', $differences->toPdf(new SerializationContext()));
        $encoding = new EncodingDictionary($differences, PredefinedEncoding::WinAnsi);
        self::assertSame('A', $encoding->glyph(new IntegerType(200))->value);
        self::assertSame('a', $encoding->glyph(new IntegerType(97))->value);
    }

    public function testDifferencesOverlapIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncodingDifferences(new EncodingDifference(new IntegerType(255), new NameType('A')), new EncodingDifference(new IntegerType(255), new NameType('B')));
    }

    public function testDifferencesRunCannotOverflowByteCodes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncodingDifference(new IntegerType(255), new NameType('A'), new NameType('B'));
    }

    public function testUnknownBuiltInVectorMustBeSuppliedForLocalResolution(): void
    {
        $encoding = new EncodingDictionary(new EncodingDifferences(new EncodingDifference(new IntegerType(65), new NameType('A'))));
        self::assertSame('A', $encoding->glyph(new IntegerType(65))->value);
        $this->expectException(\LogicException::class);
        $encoding->glyph(new IntegerType(66));
    }

    public function testSimpleFontModelsAndCompleteStandardOverride(): void
    {
        $widths = new SimpleWidths(new IntegerType(65), new NumericType(500), new NumericType(600));
        $descriptor = $this->descriptor(new FontFile3('bytes', FontFile3Subtype::Type1C));
        $type1 = new Type1Font(new NameType('Demo'), new SimpleFontMetrics($widths, $descriptor), new NamedFontEncoding(PredefinedEncoding::WinAnsi), $this->unicode());
        self::assertSame('Type1', $type1->get('Subtype')->value);
        self::assertSame(66, $type1->get('LastChar')->value);
        $trueType = new TrueTypeFont(new NameType('Demo'), new SimpleFontMetrics($widths, $this->descriptor(new FontFile2('bytes'))), new NamedFontEncoding(PredefinedEncoding::WinAnsi));
        self::assertSame('TrueType', $trueType->get('Subtype')->value);
        $mm = new MMType1Font(new NameType('Instance 300 400 '), new SimpleFontMetrics($widths, $this->descriptor(new FontFile3('bytes', FontFile3Subtype::Type1C), new NameType('Instance_300_400_'))));
        self::assertSame('Instance_300_400_', $mm->get('BaseFont')->value);
        $standard = new Type1Font(StandardFont::Helvetica, new SimpleFontMetrics($widths, $this->descriptor(null, new NameType('Helvetica'))));
        self::assertInstanceOf(IndirectReference::class, $standard->get('FontDescriptor'));
    }

    public function testStandardMetricsCannotBePartiallyOverridden(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Type1Font(StandardFont::Helvetica, new Entry(new NameType('FirstChar'), new IntegerType(65)));
    }

    public function testCustomSimpleFontRequiresMetrics(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Type1Font(new NameType('Custom'));
    }

    public function testSimpleWidthsHaveNoOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SimpleWidths(new IntegerType(255), new NumericType(1), new NumericType(1));
    }

    public function testSimpleFontToUnicodeUsesByteCodes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Type1Font(StandardFont::Helvetica, $this->unicode(2));
    }

    public function testStandardEncodingIsNotAPredefinedPdfName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Type1Font(StandardFont::Helvetica, new Entry(new NameType('Encoding'), new NameType('StandardEncoding')));
    }

    public function testType3GraphBindsEncodingToGlyphWidthAndReference(): void
    {
        $font = $this->type3();
        self::assertSame('Type3', $font->get('Subtype')->value);
        self::assertInstanceOf(IndirectReference::class, $font->get('Widths'));
        self::assertSame('500 0 0 0 500 700 d1', trim($font->charProcs->glyphs['A']->content->__toString()));
        $this->expectException(\InvalidArgumentException::class);
        $font->with(new Entry(new NameType('CharProcs'), new DictionaryType()));
    }

    public function testType3GlyphWidthAndWidthsCannotDisagree(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->type3(600);
    }

    public function testType3FontBoxEnclosesDeclaredGlyphBoxes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->type3(right: 400);
    }

    public function testType3UncoloredGlyphRejectsColourOperators(): void
    {
        $this->expectException(\LogicException::class);
        new Type3Glyph(new Type3GlyphContentStream(new SetGlyphWidthAndBoundingBox(new NumericType(500), new NumericType(0), new NumericType(0), new NumericType(0), new NumericType(500), new NumericType(700)), new SetNonstrokingRGBColor(new NumericType(1), new NumericType(0), new NumericType(0))));
    }

    public function testCidWidthAndVerticalFormsHaveCorrectOrderAndFallback(): void
    {
        $widths = new CIDWidthArray(new CIDWidthRun(new IntegerType(120), new NumericType(400), new NumericType(325), new NumericType(500)), new CIDWidthRange(new CIDRange(new IntegerType(7080), new IntegerType(8032)), new NumericType(1000)));
        self::assertSame('[120 [400 325 500] 7080 8032 1000]', $widths->toPdf(new SerializationContext()));
        $vertical = new CIDVerticalMetrics(new CIDDefaultVerticalMetrics(), new CIDVerticalWidths(new CIDVerticalRun(new IntegerType(120), new CIDVerticalMetric(new NumericType(-1000), new NumericType(250), new NumericType(772))), new CIDVerticalRange(new CIDRange(new IntegerType(7080), new IntegerType(8032)), new CIDVerticalMetric(new NumericType(-1000), new NumericType(500), new NumericType(900)))));
        self::assertSame('[120 [-1000 250 772] 7080 8032 -1000 500 900]', $vertical->widths->toPdf(new SerializationContext()));
        $metrics = new CIDMetrics(widths: $widths, vertical: $vertical);
        self::assertSame(250, $metrics->verticalMetric(new IntegerType(120))->positionX->value);
        self::assertSame(162.5, $metrics->verticalMetric(new IntegerType(121))->positionX->value);
        self::assertSame(880, $metrics->verticalMetric(new IntegerType(121))->positionY->value);
        self::assertSame(-1000, $metrics->verticalMetric(new IntegerType(121))->displacementY->value);
        self::assertSame(900, $metrics->verticalMetric(new IntegerType(8000))->positionY->value);
        self::assertSame(1000, $metrics->width(new IntegerType(1))->value);
    }

    public function testCidWidthRangesCannotOverlap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CIDWidthArray(new CIDWidthRun(new IntegerType(1), new NumericType(500)), new CIDWidthRange(new CIDRange(new IntegerType(1), new IntegerType(2)), new NumericType(600)));
    }

    public function testCidVerticalRangeCannotOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CIDVerticalRun(new IntegerType(65535), new CIDVerticalMetric(new NumericType(-1000), new NumericType(500), new NumericType(880)), new CIDVerticalMetric(new NumericType(-1000), new NumericType(500), new NumericType(880)));
    }

    public function testExplicitCidGlyphMapAndVerticalType2Font(): void
    {
        $map = new CIDGlyphIndexMap(new CIDGlyphIndex(new IntegerType(0), new IntegerType(0)), new CIDGlyphIndex(new IntegerType(2), new IntegerType(37)));
        self::assertSame("\0\0\0\0\0\x25", $map->data);
        $font = new CIDFontType2($this->descriptor(new FontFile2('bytes')), $map, new CIDMetrics(vertical: new CIDVerticalMetrics()));
        self::assertNull($font->map);
        self::assertInstanceOf(CIDDefaultVerticalMetrics::class, $font->get('DW2'));
        self::assertSame('Identity-V', (new Type0Font($font, encoding: new IdentityEncoding(CMapWritingMode::Vertical)))->get('Encoding')->value);
        self::assertSame('Identity', (new CIDFontType2($this->descriptor(new FontFile2('bytes')), new CIDIdentityGlyphMap(), new CIDMetrics()))->get('CIDToGIDMap')->value);
    }

    public function testNonEmbeddedCidType2HasNoGlyphIndices(): void
    {
        $font = new CIDFontType2($this->descriptor(null), null, new CIDMetrics(), $this->info('Japan1'));
        self::assertFalse($font->has('CIDToGIDMap'));
        $this->expectException(\InvalidArgumentException::class);
        new CIDFontType2($this->descriptor(null), new CIDIdentityGlyphMap(), new CIDMetrics());
    }

    public function testExplicitCidMapRequiresNotdefIndexZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CIDGlyphIndexMap(new CIDGlyphIndex(new IntegerType(1), new IntegerType(37)));
    }

    public function testFontGraphHasImmutableCriticalEntries(): void
    {
        $font = new CIDFontType0($this->descriptor(new FontFile3('bytes', FontFile3Subtype::CIDFontType0C)), $this->info());
        $this->expectException(\InvalidArgumentException::class);
        $font->with(new Entry(new NameType('CIDToGIDMap'), new NameType('Identity')));
    }

    public function testActualWriterRejectsDifferentProgramsWithTheSameSubsetTag(): void
    {
        $name = new SubsetFontName(new FontSubsetTag('ABCDEF'), new NameType('Demo'));
        $first = new Type0Font(new CIDFontType2($this->descriptor(new FontFile2('normalized program A'), $name), new CIDIdentityGlyphMap(), new CIDMetrics()));
        $second = new Type0Font(new CIDFontType2($this->descriptor(new FontFile2('normalized program B'), $name), new CIDIdentityGlyphMap(), new CIDMetrics()));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Different font subsets in one PDF have different tags');
        $this->file($first, $second)->toPdf();
    }

    public function testRawNamesCannotHideSubsetIdentityFromTheDocumentRegistry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->descriptor(new FontFile2('program'), new NameType('ABCDEF+Demo'));
    }

    public function testActualWriterSharesProgramAndDoesNotLeakSubsetStateBetweenFiles(): void
    {
        $name = new SubsetFontName(new FontSubsetTag('ABCDEF'), new NameType('Demo'));
        $program = new FontFile2('normalized program A');
        $first = new Type0Font(new CIDFontType2($this->descriptor($program, $name), new CIDIdentityGlyphMap(), new CIDMetrics()));
        $second = new Type0Font(new CIDFontType2($this->descriptor($program, $name), new CIDIdentityGlyphMap(), new CIDMetrics()));
        self::assertStringStartsWith('%PDF-1.7', $this->file($first, $second)->toPdf());
        $third = new Type0Font(new CIDFontType2($this->descriptor(new FontFile2('program in another document'), $name), new CIDIdentityGlyphMap(), new CIDMetrics()));
        self::assertStringStartsWith('%PDF-1.7', $this->file($third)->toPdf());
    }

    public function testType3RequiresDescriptorInTheActualTaggedCatalog(): void
    {
        $font = $this->type3();
        $font->validateCatalog(new DictionaryType());
        $this->expectException(\InvalidArgumentException::class);
        $font->validateCatalog(new DictionaryType(new Entry(new NameType('MarkInfo'), new DictionaryType(new Entry(new NameType('Marked'), new BooleanType(true))))));
    }

    public function testType3CanHaveItsTypeSpecificMinimalDescriptor(): void
    {
        $descriptor = new FontDescriptor(null, new NameType('Shapes'), new FontDescriptorFlags(4), null, new NumericType(0), null, null, null, null);
        $original = $this->type3();
        $font = new Type3Font($original->bounds, $original->matrix, $original->charProcs, $original->encoding, $original->widths, descriptor: $descriptor);
        $font->validateCatalog(new DictionaryType(new Entry(new NameType('MarkInfo'), new DictionaryType(new Entry(new NameType('Marked'), new BooleanType(true))))));
        self::assertSame($descriptor, $font->get('FontDescriptor')->target());
    }

    public function testFontProgramMetadataKeepsItsIndirectIdentityAndActualFilter(): void
    {
        $stream = new StreamObject('<xmp/>', new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML'))));
        $program = new FontFile2('program', new FontProgramMetadata($stream), new FlateDecode());
        self::assertSame($stream, $program->dictionary()->get('Metadata')->target());
        self::assertSame('program', gzuncompress($program->bytes(new SerializationContext())));
        self::assertSame(7, $program->dictionary()->get('Length1')->value);
    }

    public function testFontProgramMetadataRejectsAnUnrelatedStream(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontProgramMetadata(new StreamObject('opaque unrelated data'));
    }

    public function testMacOsRomanLookupVectorRemainsDistinctFromPdfMacRoman(): void
    {
        $mac = new EncodingVector(EncodingVectorKind::MacOSRoman);
        self::assertSame('Euro', $mac->glyph(new IntegerType(219))->value);
        self::assertSame('apple', $mac->glyph(new IntegerType(240))->value);
        self::assertSame('notequal', $mac->glyph(new IntegerType(173))->value);
        self::assertSame('.notdef', (new EncodingVector(EncodingVectorKind::MacRoman))->glyph(new IntegerType(173))->value);
        self::assertCount(223, $mac->glyphs);
    }

    /** @return iterable<string, array{FontStream, string, string}> */
    public static function programVersionBoundaries(): iterable
    {
        yield 'TrueType' => [new FontFile2('normalized bytes'), '1.1', '1.0'];
        yield 'Type1C' => [new FontFile3('normalized bytes', FontFile3Subtype::Type1C), '1.2', '1.1'];
        yield 'CID CFF' => [new FontFile3('normalized bytes', FontFile3Subtype::CIDFontType0C), '1.3', '1.2'];
        yield 'OpenType' => [new FontFile3('normalized bytes', FontFile3Subtype::OpenType, OpenTypeOutline::Glyf), '1.6', '1.5'];
    }

    #[DataProvider('programVersionBoundaries')]
    public function testFontProgramVersionsUseTheActualSelectedFormat(FontStream $program, string $accepted, string $refused): void
    {
        $program->validateVersion(new EffectivePdfVersion(new FileHeader($accepted), new DictionaryType()));
        $this->expectException(\LogicException::class);
        $program->validateVersion(new EffectivePdfVersion(new FileHeader($refused), new DictionaryType()));
    }

    public function testActualWriterRejectsNewerFontProgramAndAcceptsCatalogVersionUpgrade(): void
    {
        $font = new TrueTypeFont(new NameType('Demo'), new SimpleFontMetrics($this->widths(), $this->descriptor(new FontFile3('normalized bytes', FontFile3Subtype::OpenType, OpenTypeOutline::Glyf))));
        $file = $this->file($font);
        $old = new FileStructure(new FileHeader('1.5'), $file->body, $file->root);
        try {
            $old->toPdf();
            self::fail('OpenType must not be written as PDF 1.5.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('requires PDF 1.6', $exception->getMessage());
        }
        $pages = (new PageTreeNode())->addKid(new PageObject($this->rectangle(), new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))))));
        $catalog = new DocumentCatalog($pages, new Entry(new NameType('Version'), new NameType('1.6')));
        self::assertStringStartsWith('%PDF-1.5', (new FileStructure(new FileHeader('1.5'), new FileBody($catalog), new IndirectReference($catalog)))->toPdf());
    }

    public function testPdf10SimpleFontNameRequirementDoesNotForceAnUnnecessaryVersionUpgrade(): void
    {
        $font = new Type1Font(StandardFont::Helvetica, new Entry(new NameType('Name'), new NameType('F1')));
        $font->validateVersion(new EffectivePdfVersion(new FileHeader('1.0'), new DictionaryType()));
        self::assertSame('F1', $font->get('Name')->value);
        $this->expectException(\LogicException::class);
        (new Type1Font(StandardFont::Helvetica))->validateVersion(new EffectivePdfVersion(new FileHeader('1.0'), new DictionaryType()));
    }

    public function testNonembeddedCidType2RemainsAvailableInPdf12ButItsTrueTypeEmbeddingNeeds13(): void
    {
        $version = new EffectivePdfVersion(new FileHeader('1.2'), new DictionaryType());
        $external = new CIDFontType2($this->descriptor(null), null, new CIDMetrics());
        $external->validateVersion($version);
        self::assertFalse($external->has('CIDToGIDMap'));
        $this->expectException(\LogicException::class);
        (new CIDFontType2($this->descriptor(new FontFile2('bytes')), new CIDIdentityGlyphMap(), new CIDMetrics()))->validateVersion($version);
    }

    public function testLowLevelDifferencesCannotBypassItsIntrinsicRules(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Type1Font(StandardFont::Helvetica, new Entry(new NameType('Encoding'), new DictionaryType(new Entry(new NameType('Differences'), new \PDF\Syntax\Object\ArrayType(new IntegerType(255), new NameType('A'), new NameType('B'))))));
    }
}
