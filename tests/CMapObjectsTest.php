<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Fonts\CMaps\CMapCode;
use PDF\Document\Fonts\CMaps\CMapCodeRange;
use PDF\Document\Fonts\CMaps\CMapCodeSequence;
use PDF\Document\Fonts\CMaps\CMapDecoder;
use PDF\Document\Fonts\CMaps\CMapName;
use PDF\Document\Fonts\CMaps\CMapSystemInfo;
use PDF\Document\Fonts\CMaps\CMapWritingMode;
use PDF\Document\Fonts\CMaps\CodeSpaceRange;
use PDF\Document\Fonts\CMaps\CodeSpaces;
use PDF\Document\Fonts\CMaps\CidChar;
use PDF\Document\Fonts\CMaps\CidGlyphs;
use PDF\Document\Fonts\CMaps\CidMappings;
use PDF\Document\Fonts\CMaps\CidRange;
use PDF\Document\Fonts\CMaps\EncodingCMap;
use PDF\Document\Fonts\CMaps\IdentityEncoding;
use PDF\Document\Fonts\CMaps\NamedCMapEncoding;
use PDF\Document\Fonts\CMaps\NamedUnicodeCMap;
use PDF\Document\Fonts\CMaps\PredefinedCMap;
use PDF\Document\Fonts\CMaps\NotdefChar;
use PDF\Document\Fonts\CMaps\NotdefRange;
use PDF\Document\Fonts\CMaps\UnicodeArrayRange;
use PDF\Document\Fonts\CMaps\UnicodeChar;
use PDF\Document\Fonts\CMaps\UnicodeCMap;
use PDF\Document\Fonts\CMaps\UnicodeMappings;
use PDF\Document\Fonts\CMaps\UnicodeRange;
use PDF\Document\Fonts\CMaps\UnicodeScalar;
use PDF\Document\Fonts\CMaps\UnicodeScalars;
use PDF\Document\Fonts\CMaps\UnicodeSequence;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Document\Fonts\Type0Font;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileHeader;
use PHPUnit\Framework\TestCase;

final class CMapObjectsTest extends TestCase
{
    public function testSupplementsDoNotDetermineCollectionCompatibility(): void
    {
        $higher = new CMapSystemInfo(new LiteralString('Adobe'), new LiteralString('Identity'), new IntegerType(4));
        $base = new EncodingCMap(new CMapName(new NameType('Higher-Revision')), $higher, new CodeSpaces($this->space()), new CidMappings());
        $derived = new EncodingCMap(new CMapName(new NameType('Same-Collection')), $this->systemInfo(), new CodeSpaces(), new CidMappings(), base: $base);
        self::assertSame(0, $derived->collection()->supplement->integer());
        $font = new Type0Font($this->descendant(), encoding: $base);
        self::assertSame($base, $font->encoding);
    }

    public function testPredefinedNamesRespectTable119VersionAvailability(): void
    {
        self::assertSame('1.2', PredefinedCMap::IdentityH->minimumVersion()->value);
        self::assertSame('1.3', PredefinedCMap::GbpcEucV->minimumVersion()->value);
        self::assertSame('1.4', PredefinedCMap::Gbk2kH->minimumVersion()->value);
        self::assertSame('1.5', PredefinedCMap::UniJisUtf16H->minimumVersion()->value);
        $encoding = new NamedCMapEncoding(PredefinedCMap::UniJisUtf16H, new CMapSystemInfo(new LiteralString('Adobe'), new LiteralString('Japan1'), new IntegerType(5)), new CodeSpaces($this->space('0000', 'ffff')));
        $encoding->validateVersion(new EffectivePdfVersion(new FileHeader('1.5'), new DictionaryType()));
        $this->expectException(\LogicException::class);
        $encoding->validateVersion(new EffectivePdfVersion(new FileHeader('1.4'), new DictionaryType()));
    }

    private function code(string $hex): CMapCode
    {
        return new CMapCode(new HexadecimalString(hex2bin($hex)));
    }
    private function range(string $low, string $high): CMapCodeRange
    {
        return new CMapCodeRange($this->code($low), $this->code($high));
    }
    private function space(string $low = '00', string $high = 'ff'): CodeSpaceRange
    {
        return new CodeSpaceRange($this->code($low), $this->code($high));
    }
    private function scalar(int $point): UnicodeSequence
    {
        return (new UnicodeScalar(new IntegerType($point)))->sequence();
    }
    private function systemInfo(): CMapSystemInfo
    {
        return new CMapSystemInfo(new LiteralString('Adobe'), new LiteralString('Identity'), new IntegerType(0));
    }
    private function encoding(?CidMappings $mappings = null): EncodingCMap
    {
        return new EncodingCMap(new CMapName(new NameType('Explicit-Encoding')), $this->systemInfo(), new CodeSpaces($this->space()), $mappings ?? new CidMappings());
    }
    private function descendant(bool $embedded = true, string $subtype = 'CIDFontType2'): DictionaryType
    {
        $descriptor = new DictionaryType(new Entry(new NameType('FontName'), new NameType('ExplicitFont')));
        if ($embedded) {
            $program = $subtype === 'CIDFontType0' ? new StreamObject('explicit normalized CFF program', new DictionaryType(new Entry(new NameType('Subtype'), new NameType('CIDFontType0C')))) : new StreamObject('explicit normalized TrueType program');
            $descriptor = $descriptor->with(new Entry(new NameType($subtype === 'CIDFontType0' ? 'FontFile3' : 'FontFile2'), new IndirectReference($program)));
        }
        return new DictionaryType(new Entry(new NameType('Type'), new NameType('Font')), new Entry(new NameType('Subtype'), new NameType($subtype)), new Entry(new NameType('BaseFont'), new NameType('ExplicitFont')), new Entry(new NameType('CIDSystemInfo'), $this->systemInfo()->dictionary()), new Entry(new NameType('FontDescriptor'), new IndirectReference($descriptor)));
    }

    public function testSourceCodesPreserveByteWidthAndUnsignedValue(): void
    {
        foreach (['00' => 0, 'ffff' => 65535, 'ffffff' => 16777215, 'ffffffff' => 4294967295] as $hex => $number) {
            self::assertEquals($number, $this->code($hex)->number());
            self::assertSame('<' . strtoupper($hex) . '>', $this->code($hex)->hex());
        }
    }
    public function testCodeCannotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->code('');
    }
    public function testCodeCannotHaveFiveBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->code('0000000000');
    }
    public function testBigEndianSourceIncrementCarriesWithinTheDeclaredWidth(): void
    {
        self::assertSame('<80000000>', $this->code('7fffffff')->add(1)->hex());
        self::assertSame('<0100>', $this->code('00ff')->add(1)->hex());
    }
    public function testSourceIncrementCannotOverflowWidth(): void
    {
        $this->expectException(\OverflowException::class);
        $this->code('ffffffff')->add(1);
    }
    public function testCodespaceUsesIndividualByteBounds(): void
    {
        $space = $this->space('8140', '9ffc');
        self::assertTrue($space->contains($this->code('8740')));
        self::assertFalse($space->contains($this->code('8800')));
        self::assertFalse($space->contains($this->code('82ff')));
        self::assertTrue((new CodeSpaces($space))->covers($this->range('8140', '817e')));
        self::assertFalse((new CodeSpaces($space))->covers($this->range('8140', '8240')));
    }
    public function testCodespaceLowerBytesCannotExceedUpperBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->space('00ff', '0100');
    }
    public function testCodespacePrefixesCannotMakeOneAndTwoByteCodesAmbiguous(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CodeSpaces($this->space('20', '7f'), $this->space('2000', '2fff'));
    }
    public function testCodespacesWithDifferentWidthsDecodeWithoutAmbiguity(): void
    {
        $spaces = new CodeSpaces($this->space('00', '7f'), $this->space('8100', '81ff'), $this->space('820000', '82ffff'), $this->space('83000000', '83ffffff'));
        $codes = $spaces->decode(new HexadecimalString("A\x81\x12\x82\x34\x56\x83\x78\x9a\xbc"));
        self::assertSame([1, 2, 3, 4], array_map(static fn (CMapCode $code) => $code->length(), $codes->codes));
        self::assertSame("A\x81\x12\x82\x34\x56\x83\x78\x9a\xbc", $codes->string()->value);
    }
    public function testProducerDecodingRejectsTruncatedCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CodeSpaces($this->space('8100', '81ff')))->decode(new HexadecimalString("\x81"));
    }
    public function testFullFourByteCoverageDoesNotEnumerateFourBillionCodes(): void
    {
        $spaces = new CodeSpaces($this->space('00000000', 'ffffff7f'), $this->space('00000080', 'ffffffff'));
        self::assertTrue($spaces->covers($this->range('00000000', 'ffffffff')));
        self::assertTrue($spaces->coversSpace($this->space('00000000', 'ffffffff')));
    }
    public function testRangeCoverageAgreesWithIndependentSmallDomainEnumeration(): void
    {
        $spaces = new CodeSpaces($this->space('0000', '0307'), $this->space('0008', '031f'));
        for ($low = 0; $low < 1024; $low += 23) {
            for ($distance = 0; $distance < 43; $distance += 7) {
                $high = min(1023, $low + $distance);
                $expected = true;
                for ($code = $low; $code <= $high; ++$code) {
                    $expected = $expected && ($code & 255) <= 31;
                }
                self::assertSame($expected, $spaces->covers(new CMapCodeRange(new CMapCode(new HexadecimalString(pack('n', $low))), new CMapCode(new HexadecimalString(pack('n', $high))))));
            }
        }
    }
    public function testCidRangeIncrementsAndNotdefRangeRemainsConstant(): void
    {
        $mapping = new CidRange($this->range('41', '43'), new IntegerType(100));
        $fallback = new NotdefRange($this->range('41', '43'), new IntegerType(7));
        self::assertSame(102, $mapping->cid($this->code('43'))->integer());
        self::assertSame(7, $fallback->cid($this->code('43'))->integer());
    }
    public function testCidRangesCannotOverflowTheSixteenBitDestination(): void
    {
        $this->expectException(\OverflowException::class);
        new CidRange($this->range('41', '43'), new IntegerType(65534));
    }
    public function testCidMappingsCanUseCidZero(): void
    {
        self::assertSame(0, (new CidChar($this->code('41'), new IntegerType(0)))->cid($this->code('41'))->integer());
    }
    public function testNormalCidMappingsCannotOverlap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CidMappings(new CidRange($this->range('41', '43'), new IntegerType(1)), new CidChar($this->code('42'), new IntegerType(9)));
    }
    public function testOrdinaryAndNotdefMappingsMayOverlapAndResolveMissingGlyphs(): void
    {
        $encoding = $this->encoding(new CidMappings(new CidChar($this->code('41'), new IntegerType(100)), new NotdefChar($this->code('41'), new IntegerType(9))));
        self::assertSame(9, $encoding->resolve($this->code('41'), new CidGlyphs(new IntegerType(0), new IntegerType(9)))->integer());
        self::assertSame(100, $encoding->resolve($this->code('41'), new CidGlyphs(new IntegerType(0), new IntegerType(100)))->integer());
        self::assertSame(0, $encoding->resolve($this->code('41'), new CidGlyphs(new IntegerType(0)))->integer());
    }
    public function testMappingCannotEscapeItsCodespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncodingCMap(new CMapName(new NameType('Bad')), $this->systemInfo(), new CodeSpaces($this->space('00', '7f')), new CidMappings(new CidChar($this->code('a0'), new IntegerType(2))));
    }
    public function testUndefinedCodeUsesShortestRangeWhenNoFirstByteMatches(): void
    {
        $decoder = new CMapDecoder(new CodeSpaces($this->space('8140', '817e'), $this->space('900000', '90ffff')));
        $codes = $decoder->decode(new HexadecimalString("\x20\x21\x81\x40"));
        self::assertFalse($codes[0]->defined);
        self::assertSame(2, $codes[0]->expectedLength->integer());
        self::assertSame('<2021>', $codes[0]->code->hex());
        self::assertTrue($codes[1]->defined);
    }
    public function testUndefinedCodeUsesLongestPartialMatchAndShortestWidthOnTies(): void
    {
        $decoder = new CMapDecoder(new CodeSpaces($this->space('814010', '817e1f'), $this->space('81802000', '81fc20ff')));
        $codes = $decoder->decode(new HexadecimalString("\x81\x40\xff\x81\x80\x21\x01"));
        self::assertSame([3, 4], array_map(static fn ($code) => $code->expectedLength->integer(), $codes));
        self::assertSame('<8140FF>', $codes[0]->code->hex());
        self::assertSame('<81802101>', $codes[1]->code->hex());
    }
    public function testTruncatedUndefinedCodeConsumesRemainingBytes(): void
    {
        $codes = (new CMapDecoder(new CodeSpaces($this->space('8140', '817e'))))->decode(new HexadecimalString("\x81"));
        self::assertFalse($codes[0]->defined);
        self::assertSame(2, $codes[0]->expectedLength->integer());
        self::assertSame(1, $codes[0]->code->length());
        self::assertSame(0, $this->encoding()->resolve($codes[0], new CidGlyphs(new IntegerType(0)))->integer());
    }
    public function testUnicodeScalarEncodesSupplementaryCharacters(): void
    {
        self::assertSame('<D83DDE42>', $this->scalar(0x1f642)->hex());
        self::assertSame('🙂', $this->scalar(0x1f642)->utf8());
        self::assertSame('<DBFFDFFF>', $this->scalar(0x10ffff)->hex());
    }
    public function testUnicodeScalarRejectsSurrogates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scalar(0xd800);
    }
    public function testUnicodeSequenceRejectsUnpairedHighSurrogate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnicodeSequence(new HexadecimalString("\xd8\x00\0A"));
    }
    public function testUnicodeSequenceRejectsUnpairedLowSurrogate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnicodeSequence(new HexadecimalString("\xdc\x00"));
    }
    public function testUnicodeSequenceRejectsOddByteCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnicodeSequence(new HexadecimalString('A'));
    }
    public function testUnicodeSequencesSupportLigaturesAndMaximumLength(): void
    {
        $ligature = new UnicodeScalars(new UnicodeScalar(new IntegerType(0x66)), new UnicodeScalar(new IntegerType(0x69)));
        self::assertSame('<00660069>', $ligature->hex());
        self::assertSame('fi', $ligature->utf8());
        self::assertSame(512, strlen((new UnicodeSequence(new HexadecimalString(str_repeat("\0A", 256))))->value->value));
    }
    public function testUnicodeSequencesCannotExceed512Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnicodeSequence(new HexadecimalString(str_repeat("\0A", 257)));
    }
    public function testUnicodeRangeIncrementsOnlyLastByte(): void
    {
        $mapping = new UnicodeRange($this->range('41', '43'), $this->scalar(0x41));
        self::assertSame('C', $mapping->sequence($this->code('43'))->utf8());
    }
    public function testUnicodeRangeCannotCarryIntoPreviousDestinationByte(): void
    {
        $this->expectException(\OverflowException::class);
        new UnicodeRange($this->range('41', '42'), $this->scalar(0x00ff));
    }
    public function testUnicodeRangeCannotContainMoreThan256IncrementedDestinations(): void
    {
        $this->expectException(\OverflowException::class);
        new UnicodeRange($this->range('0000', '0100'), $this->scalar(0x0000));
    }
    public function testUnicodeArrayRangePreservesDiscontiguousLigaturesAndSurrogates(): void
    {
        $mapping = new UnicodeArrayRange($this->range('a0', 'a2'), $this->scalar(0xe9), new UnicodeScalars(new UnicodeScalar(new IntegerType(0x66)), new UnicodeScalar(new IntegerType(0x69))), $this->scalar(0x1f642));
        self::assertSame('é', $mapping->sequence($this->code('a0'))->utf8());
        self::assertSame('fi', $mapping->sequence($this->code('a1'))->utf8());
        self::assertSame('🙂', $mapping->sequence($this->code('a2'))->utf8());
        self::assertStringContainsString('[<00E9> <00660069> <D83DDE42>]', $mapping->line());
    }
    public function testUnicodeArrayRangeRequiresExactlyOneDestinationPerSourceCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnicodeArrayRange($this->range('a0', 'a2'), $this->scalar(0x41), $this->scalar(0x42));
    }
    public function testUnicodeMappingsCannotOverlap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnicodeMappings(new UnicodeRange($this->range('41', '43'), $this->scalar(0x41)), new UnicodeChar($this->code('42'), $this->scalar(0x42)));
    }
    public function testCMapDictionaryAndProgramUseIdenticalMetadata(): void
    {
        $map = new EncodingCMap(new CMapName(new NameType('Literal#Resource')), $this->systemInfo(), new CodeSpaces($this->space()), new CidMappings(new CidChar($this->code('41'), new IntegerType(1))), CMapWritingMode::Vertical, null, new FlateDecode());
        self::assertSame('Literal#Resource', $map->dictionary()->get('CMapName')->value);
        self::assertStringContainsString('/CMapName /Literal#Resource def', $map->data);
        self::assertStringContainsString('/WMode 1 def', $map->data);
        self::assertSame(1, $map->dictionary()->get('WMode')->integer());
        self::assertSame($map->data, gzuncompress($map->bytes(new SerializationContext())));
    }
    public function testCMapNameCannotInjectPostScriptInstructions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CMapName(new NameType('Bad Name /Other def'));
    }
    public function testEncodingBaseIsMatchedByUseCMapDictionaryAndProgram(): void
    {
        $base = $this->encoding(new CidMappings(new CidChar($this->code('41'), new IntegerType(1))));
        $child = new EncodingCMap(new CMapName(new NameType('Derived')), $this->systemInfo(), new CodeSpaces(), new CidMappings(new CidChar($this->code('42'), new IntegerType(2))), base: $base);
        self::assertSame($base, $child->dictionary()->get('UseCMap')->target());
        self::assertStringNotContainsString('/Explicit-Encoding usecmap', $child->data);
        self::assertSame(1, $child->cid($this->code('41'))->integer());
        self::assertSame(2, $child->cid($this->code('42'))->integer());
    }
    public function testInheritedCidMappingCanBeDeliberatelyOverridden(): void
    {
        $base = $this->encoding(new CidMappings(new CidChar($this->code('41'), new IntegerType(1))));
        $child = new EncodingCMap(new CMapName(new NameType('Derived')), $this->systemInfo(), new CodeSpaces(), new CidMappings(new CidChar($this->code('41'), new IntegerType(99))), base: $base);
        self::assertSame(99, $child->cid($this->code('41'))->integer());
    }
    public function testToUnicodeInheritanceUsesStreamAndExplicitLocalOverrides(): void
    {
        $base = new ToUnicodeCMap(new UnicodeCMap(new CMapName(new NameType('Base-UCS')), new CodeSpaces($this->space()), new UnicodeMappings(new UnicodeChar($this->code('41'), $this->scalar(0x41)))));
        $child = new ToUnicodeCMap(new UnicodeCMap(new CMapName(new NameType('Derived-UCS')), new CodeSpaces(), new UnicodeMappings(new UnicodeChar($this->code('42'), $this->scalar(0x42))), $base));
        self::assertSame($base, $child->dictionary()->get('UseCMap')->target());
        self::assertStringNotContainsString('/Base-UCS usecmap', $child->data);
        self::assertSame('A', $child->sequence($this->code('41'))->utf8());
        self::assertSame('B', $child->sequence($this->code('42'))->utf8());
    }

    public function testToUnicodeDictionaryAndProgramShareTheirMetadata(): void
    {
        $info = new CMapSystemInfo(new LiteralString('Example'), new LiteralString('Unicode'), new IntegerType(4));
        $unicode = new ToUnicodeCMap(new UnicodeCMap(new CMapName(new NameType('Vertical-UCS')), new CodeSpaces($this->space()), new UnicodeMappings(new UnicodeChar($this->code('41'), $this->scalar(0x41))), systemInfo: $info, mode: CMapWritingMode::Vertical));
        self::assertSame('CMap', $unicode->dictionary()->get('Type')->value);
        self::assertSame('Vertical-UCS', $unicode->dictionary()->get('CMapName')->value);
        self::assertSame(1, $unicode->dictionary()->get('WMode')->value);
        self::assertSame($info->registry, $unicode->dictionary()->get('CIDSystemInfo')->get('Registry'));
        self::assertStringContainsString('/CMapName /Vertical-UCS def', $unicode->data);
        self::assertStringContainsString('/WMode 1 def', $unicode->data);
        self::assertStringContainsString('/CIDSystemInfo ' . $info->program() . ' def', $unicode->data);
        self::assertSame('A', $unicode->sequence($this->code('41'))->utf8());
    }
    public function testType0FontCanSelectIdentityV(): void
    {
        $font = new Type0Font($this->descendant(), encoding: new IdentityEncoding(CMapWritingMode::Vertical));
        self::assertSame('Identity-V', $font->get('Encoding')->value);
        self::assertFalse($font->has('ToUnicode'));
    }
    public function testType0FontCanSelectEmbeddedCMapAndGeneralToUnicode(): void
    {
        $encoding = $this->encoding(new CidMappings(new CidChar($this->code('41'), new IntegerType(1))));
        $unicode = new ToUnicodeCMap(new UnicodeCMap(new CMapName(new NameType('Explicit-UCS')), new CodeSpaces($this->space()), new UnicodeMappings(new UnicodeChar($this->code('41'), $this->scalar(0x41)))));
        $font = new Type0Font($this->descendant(), $unicode, encoding: $encoding);
        self::assertSame($encoding, $font->get('Encoding')->target());
        self::assertSame($unicode, $font->get('ToUnicode')->target());
    }
    public function testNonembeddedType2FontCannotSelectIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Type0Font($this->descendant(false));
    }
    public function testType0FontRejectsInconsistentToUnicodeCodespace(): void
    {
        $unicode = new ToUnicodeCMap(new UnicodeCMap(new CMapName(new NameType('Explicit-UCS')), new CodeSpaces($this->space()), new UnicodeMappings()));
        $this->expectException(\InvalidArgumentException::class);
        new Type0Font($this->descendant(), $unicode, encoding: new IdentityEncoding());
    }
    public function testNamedEncodingRetainsExplicitMetadataAndNeedsNoFontEmbedding(): void
    {
        $encoding = new NamedCMapEncoding(PredefinedCMap::Rksj90msH, new CMapSystemInfo(new LiteralString('Adobe'), new LiteralString('Japan1'), new IntegerType(2)), new CodeSpaces($this->space('00', '80'), $this->space('8140', '9ffc'), $this->space('a0', 'df'), $this->space('e040', 'fcfc')));
        $descendant = $this->descendant(false)->with(new Entry(new NameType('CIDSystemInfo'), $encoding->collection()->dictionary()));
        $font = new Type0Font($descendant, encoding: $encoding);
        self::assertSame('90ms-RKSJ-H', $font->get('Encoding')->value);
        self::assertSame(CMapWritingMode::Horizontal, $font->encoding->mode());
    }
    public function testNamedResourceLookupDoesNotSilentlyLoadOrInventMappings(): void
    {
        $encoding = new NamedCMapEncoding(PredefinedCMap::Rksj90msH, new CMapSystemInfo(new LiteralString('Adobe'), new LiteralString('Japan1'), new IntegerType(2)), new CodeSpaces($this->space()));
        $this->expectException(\LogicException::class);
        $encoding->cid($this->code('41'));
    }
    public function testNamedUnicodeResourceCanDeclareItsOwnResolvedMappings(): void
    {
        $named = new NamedUnicodeCMap(new CMapName(new NameType('Adobe-Japan1-UCS2')), new CodeSpaces($this->space('0000', 'ffff')), new UnicodeMappings(new UnicodeChar($this->code('0001'), $this->scalar(0x41))));
        self::assertSame('A', $named->sequence($this->code('0001'))->utf8());
    }
    public function testCMapMappingBlocksContainAtMost100Entries(): void
    {
        $mappings = [];
        for ($index = 0; $index < 201; ++$index) {
            $mappings[] = new UnicodeChar(new CMapCode(new HexadecimalString(pack('n', $index))), $this->scalar(0x41));
        }
        $stream = new ToUnicodeCMap(new UnicodeCMap(new CMapName(new NameType('Chunked-UCS')), new CodeSpaces($this->space('0000', 'ffff')), new UnicodeMappings(...$mappings)));
        self::assertSame(2, substr_count($stream->data, '100 beginbfchar'));
        self::assertStringContainsString('1 beginbfchar', $stream->data);
    }

    public function testAll61PredefinedNamesRetainExactCaseAndWritingMode(): void
    {
        self::assertCount(61, PredefinedCMap::cases());
        self::assertSame('GBKp-EUC-H', PredefinedCMap::GbkpEucH->name()->value->value);
        self::assertSame('HKscs-B5-V', PredefinedCMap::HkscsB5V->name()->value->value);
        self::assertSame('KSCms-UHC-HW-V', PredefinedCMap::KscmsUhcHwV->name()->value->value);
        self::assertSame(CMapWritingMode::Vertical, PredefinedCMap::V->mode());
        self::assertSame(CMapWritingMode::Horizontal, PredefinedCMap::H->mode());
        self::assertSame('Japan1', PredefinedCMap::H->ordering());
        self::assertSame('CNS1', PredefinedCMap::UniCnsUtf16V->ordering());
        self::assertSame('Korea1', PredefinedCMap::KscEucH->ordering());
        self::assertSame('GB1', PredefinedCMap::Gbk2kH->ordering());
    }
    public function testNamedEncodingCannotChangeItsStandardCidCollection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NamedCMapEncoding(PredefinedCMap::Rksj90msH, $this->systemInfo(), new CodeSpaces($this->space()));
    }
    public function testNamedUseCMapIsDeclaredInBothDictionaryAndProgram(): void
    {
        $map = new EncodingCMap(new CMapName(new NameType('Identity-Override')), $this->systemInfo(), new CodeSpaces(), new CidMappings(new CidChar($this->code('0041'), new IntegerType(1))), base: new IdentityEncoding());
        self::assertSame('Identity-H', $map->dictionary()->get('UseCMap')->value);
        self::assertStringContainsString('/Identity-H usecmap', $map->data);
        self::assertSame(66, $map->cid($this->code('0042'))->integer());
        self::assertSame(1, $map->cid($this->code('0041'))->integer());
    }
    public function testNamedToUnicodeBaseIsDeclaredInBothDictionaryAndProgram(): void
    {
        $base = new NamedUnicodeCMap(new CMapName(new NameType('Adobe-Japan1-UCS2')), new CodeSpaces($this->space('0000', 'ffff')), new UnicodeMappings(new UnicodeChar($this->code('0001'), $this->scalar(0x41))));
        $map = new ToUnicodeCMap(new UnicodeCMap(new CMapName(new NameType('Derived-UCS')), new CodeSpaces(), new UnicodeMappings(), $base));
        self::assertSame('Adobe-Japan1-UCS2', $map->dictionary()->get('UseCMap')->value);
        self::assertStringContainsString('/Adobe-Japan1-UCS2 usecmap', $map->data);
        self::assertSame('A', $map->sequence($this->code('0001'))->utf8());
    }
    public function testEncodingBaseCannotReferToItsOwnResourceName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EncodingCMap(new CMapName(new NameType('Identity-H')), $this->systemInfo(), new CodeSpaces(), new CidMappings(), base: new IdentityEncoding());
    }
    public function testType0RejectsDifferentFontAndCMapCollections(): void
    {
        $collection = new CMapSystemInfo(new LiteralString('Adobe'), new LiteralString('Japan1'), new IntegerType(2));
        $encoding = new EncodingCMap(new CMapName(new NameType('Japan-Explicit')), $collection, new CodeSpaces($this->space()), new CidMappings());
        $this->expectException(\InvalidArgumentException::class);
        new Type0Font($this->descendant(), encoding: $encoding);
    }
    public function testGenericCidFontType0DescendantIsAccepted(): void
    {
        $font = new Type0Font($this->descendant(subtype: 'CIDFontType0'), encoding: $this->encoding());
        self::assertSame('CIDFontType0', $font->get('DescendantFonts')->items[0]->target()->get('Subtype')->value);
    }
    public function testDerivedCodespaceCanExtendBaseWithoutInventingMappings(): void
    {
        $base = new EncodingCMap(new CMapName(new NameType('Short-Base')), $this->systemInfo(), new CodeSpaces($this->space('00', '7f')), new CidMappings());
        $map = new EncodingCMap(new CMapName(new NameType('Extended-Space')), $this->systemInfo(), new CodeSpaces($this->space('8100', '81ff')), new CidMappings(), base: $base);
        self::assertNull($map->cid($this->code('8101')));
        self::assertSame(0, $map->resolve($this->code('8101'), new CidGlyphs(new IntegerType(0)))->integer());
    }
}
