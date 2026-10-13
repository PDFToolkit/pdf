<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Fonts\CIDFontType2;
use PDF\Document\Fonts\FontDescriptor;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\Fonts\TrueType\BinaryReader;
use PDF\Document\Fonts\TrueType\CharacterMap;
use PDF\Document\Fonts\TrueType\CIDFontProgram;
use PDF\Document\Fonts\TrueType\CIDToGIDMap;
use PDF\Document\Fonts\TrueType\CIDWidths;
use PDF\Document\Fonts\TrueType\FontFile2;
use PDF\Document\Fonts\TrueType\FontProgram;
use PDF\Document\Fonts\TrueType\FontProgramLimits;
use PDF\Document\Fonts\TrueType\GlyphOutlines;
use PDF\Document\Fonts\TrueType\HorizontalMetrics;
use PDF\Document\Fonts\TrueType\TextEncoder;
use PDF\Document\Fonts\TrueType\SfntChecksum;
use PDF\Document\Fonts\TrueType\ToUnicodeCMap;
use PDF\Document\Fonts\TrueType\UnicodeCMap;
use PDF\Document\Fonts\TrueType\UnicodeText;
use PDF\Document\Fonts\Type0Font;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\FilterName;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrueTypeFontsTest extends TestCase
{
    private function bytes(): string
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/fonts/PDFFixture-Regular.ttf');
        self::assertIsString($bytes);
        return $bytes;
    }

    private function program(): FontProgram
    {
        return new FontProgram($this->bytes());
    }

    private function tableOffset(string $bytes, string $tag): int
    {
        $reader = new BinaryReader($bytes);
        for ($index = 0; $index < $reader->uint16(4); ++$index) {
            $record = 12 + $index * 16;
            if ($reader->slice($record, 4) === $tag) {
                return $reader->uint32($record + 8);
            }
        }
        throw new \LogicException('Fixture table not found.');
    }

    private function map(?FontProgram $program = null): CharacterMap
    {
        return new CharacterMap($program ?? $this->program(), new UnicodeText('AΑ🙂AHB '));
    }

    private function descriptor(FontProgram $program): FontDescriptor
    {
        return new FontDescriptor(new FontFile2(new CIDFontProgram($program)), new FontDescriptorFlags(4), new NumericType(80));
    }

    public function testProgramMetricsAreScaledWithoutRoundingAndRepeatLastAdvance(): void
    {
        $program = $this->program();
        self::assertSame('PDFFixture-Regular', $program->postScriptName);
        self::assertSame(6, $program->glyphCount);
        self::assertSame(12, $program->cmap->format);
        self::assertSame(2048, $program->metrics->unitsPerEm);
        self::assertSame(5, $program->table('hhea')->uint16(34));
        self::assertSame(500.0, $program->metrics->pdfWidth(2));
        self::assertSame(703.125, $program->metrics->pdfWidth(3));
        self::assertSame(625.0, $program->metrics->pdfWidth(5));
        self::assertSame(1500, $program->capHeight);
        self::assertSame([80, 0, 960, 1500], $program->outlines->boundingBox(2));
    }

    public function testUnicodeAliasesHaveDistinctCidsAndTheirOwnToUnicodeMappings(): void
    {
        $map = $this->map();
        self::assertCount(6, $map->mappings);
        self::assertSame($map->character(65)->glyphId, $map->character(0x391)->glyphId);
        self::assertSame(1, $map->character(65)->cid);
        self::assertSame(2, $map->character(0x391)->cid);
        self::assertSame('<0001000200030001>', (string) (new TextEncoder($map))->encode(new UnicodeText('AΑ🙂A')));
        self::assertSame("\x00\x00\x00\x02\x00\x02\x00\x04\x00\x03\x00\x05\x00\x01", (new CIDToGIDMap($map))->data);
        $unicode = new ToUnicodeCMap($map);
        self::assertStringContainsString('<0001> <0041>', $unicode->data);
        self::assertStringContainsString('<0002> <0391>', $unicode->data);
        self::assertStringContainsString('<0003> <D83DDE42>', $unicode->data);
        self::assertStringContainsString('6 beginbfchar', $unicode->data);
        self::assertSame('[0 [500 500 500 625 703.125 625 250]]', (string) new CIDWidths($map));
    }

    public function testFontGraphUsesIndirectStreamsAndPreservesTheWholeProgram(): void
    {
        $program = $this->program();
        $map = $this->map($program);
        $cidProgram = new CIDFontProgram($program);
        $file = new FontFile2($cidProgram, new FlateDecode());
        $context = new SerializationContext();
        self::assertSame($cidProgram->bytes, gzuncompress($file->bytes($context)));
        self::assertSame(strlen($cidProgram->bytes), $file->dictionary()->get('Length1')?->value);
        self::assertArrayNotHasKey('cmap', $cidProgram->tables);
        self::assertSame(0xB1B0AFBA, (new SfntChecksum($cidProgram->bytes))->value);
        foreach ($program->tables() as $tag => $table) {
            if (!in_array($tag, ['cmap', 'head', 'DSIG'], true)) {
                self::assertSame($table->slice(0, $table->length()), $cidProgram->tables[$tag]->bytes);
            }
        }
        $descriptor = new FontDescriptor($file, new FontDescriptorFlags(4), new NumericType(80));
        $cid = new CIDFontType2($descriptor, new CIDToGIDMap($map), new CIDWidths($map));
        $font = new Type0Font($cid, new ToUnicodeCMap($map));
        $context->prepare($font);
        self::assertStringContainsString('/Encoding /Identity-H', $font->toPdf($context));
        self::assertStringContainsString('/Subtype /CIDFontType2', $cid->toPdf($context));
        self::assertStringContainsString('/Ascent 781.25', $descriptor->toPdf($context));
        self::assertStringContainsString('/Descent -195.3125', $descriptor->toPdf($context));
        self::assertInstanceOf(IndirectReference::class, $font->get('ToUnicode'));
        self::assertInstanceOf(IndirectReference::class, $cid->get('CIDToGIDMap'));
        self::assertInstanceOf(IndirectReference::class, $descriptor->get('FontFile2'));
    }

    public function testDeclaredRepertoireCannotSilentlyGrowDuringEncoding(): void
    {
        $encoder = new TextEncoder(new CharacterMap($this->program(), new UnicodeText('A')));
        $this->expectException(\OutOfRangeException::class);
        $encoder->encode(new UnicodeText('H'));
    }

    public function testMissingGlyphIsRejectedInsteadOfReplacingTextWithNotdef(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('U+00E9');
        new CharacterMap($this->program(), new UnicodeText('é'));
    }

    public function testFontGraphRejectsARepertoireFromAnotherProgramInstance(): void
    {
        $map = $this->map();
        $this->expectException(\InvalidArgumentException::class);
        new CIDFontType2($this->descriptor($this->program()), new CIDToGIDMap($map), new CIDWidths($map));
    }

    public function testToUnicodeCannotBeExchangedForAnotherRepertoire(): void
    {
        $program = $this->program();
        $map = $this->map($program);
        $cid = new CIDFontType2($this->descriptor($program), new CIDToGIDMap($map), new CIDWidths($map));
        $this->expectException(\InvalidArgumentException::class);
        new Type0Font($cid, new ToUnicodeCMap(new CharacterMap($program, new UnicodeText('A'))));
    }

    public function testManagedEntriesCannotBeReplacedOrRemovedButExtensionsRemainImmutable(): void
    {
        $program = $this->program();
        $map = $this->map($program);
        $descriptor = $this->descriptor($program);
        $cid = new CIDFontType2($descriptor, new CIDToGIDMap($map), new CIDWidths($map));
        $font = new Type0Font($cid, new ToUnicodeCMap($map));
        $extended = $font->with(new Entry(new NameType('PrivateApplicationTag'), new NameType('Example')));
        self::assertFalse($font->has('PrivateApplicationTag'));
        self::assertTrue($extended->has('PrivateApplicationTag'));
        foreach ([[$descriptor, 'FontFile3'], [$cid, 'W2'], [$font, 'ToUnicode']] as [$object, $key]) {
            try {
                $object->without($key);
                self::fail('Managed font entries must be protected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue($object->has($key) || in_array($key, ['FontFile3', 'W2'], true));
            }
            try {
                $object->with(new Entry(new NameType($key), new NameType('Corrupted')));
                self::fail('Managed font entries must be protected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUtf8(): iterable
    {
        yield 'overlong' => ["\xC0\xAF"];
        yield 'surrogate' => ["\xED\xA0\x80"];
        yield 'beyond Unicode' => ["\xF4\x90\x80\x80"];
        yield 'truncated' => ["\xE2\x82"];
        yield 'continuation' => ["\x80"];
    }

    #[DataProvider('invalidUtf8')]
    public function testInvalidUtf8IsRejected(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UnicodeText($text);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function embeddingRights(): iterable
    {
        yield 'installable' => [0, true];
        yield 'preview printing' => [4, true];
        yield 'editable' => [8, true];
        yield 'no subset' => [0x100, true];
        yield 'restricted' => [2, false];
        yield 'bitmap only' => [0x200, false];
        yield 'conflicting' => [12, false];
        yield 'reserved' => [1, false];
    }

    #[DataProvider('embeddingRights')]
    public function testEmbeddingRightsAreRespected(int $rights, bool $allowed): void
    {
        $bytes = $this->bytes();
        $bytes = substr_replace($bytes, pack('n', $rights), $this->tableOffset($bytes, 'OS/2') + 8, 2);
        if (!$allowed) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $program = new FontProgram($bytes);
        self::assertSame($rights, $program->embeddingRights);
        self::assertSame($program->table('glyf')->slice(0, $program->table('glyf')->length()), (new CIDFontProgram($program))->tables['glyf']->bytes);
    }

    public function testTruncatedTablesAndOutOfBoundsDirectoryOffsetsAreRejected(): void
    {
        $bytes = $this->bytes();
        foreach ([substr($bytes, 0, 9), substr_replace($bytes, pack('N', 0xFFFFFFFF), 20, 4), substr_replace($bytes, pack('N', 0xFFFFFFFF), 24, 4)] as $invalid) {
            try {
                new FontProgram($invalid);
                self::fail('Invalid sfnt bounds must fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedHeaders(): iterable
    {
        yield 'CFF' => ['OTTO'];
        yield 'collection' => ['ttcf'];
        yield 'WOFF' => ['wOFF'];
        yield 'WOFF2' => ['wOF2'];
    }

    #[DataProvider('unsupportedHeaders')]
    public function testUnsupportedProgramsAreRejected(string $header): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontProgram(substr_replace($this->bytes(), $header, 0, 4));
    }

    public function testVariableFontTableIsRejected(): void
    {
        $bytes = $this->bytes();
        $this->expectException(\InvalidArgumentException::class);
        new FontProgram(substr_replace($bytes, 'fvar', 12, 4));
    }

    public function testZeroUnitsPerEmAndInvalidHmetricsAreRejected(): void
    {
        $bytes = $this->bytes();
        foreach ([['head', 18, 0], ['hhea', 34, 0], ['hhea', 34, 7]] as [$tag, $offset, $value]) {
            try {
                new FontProgram(substr_replace($bytes, pack('n', $value), $this->tableOffset($bytes, $tag) + $offset, 2));
                self::fail('Invalid metrics must fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFormat4GlyphArrayAndDeltaPreserveMissingGlyphZero(): void
    {
        $subtable = pack('n*', 4, 36, 0, 4, 4, 1, 0, 66, 0xFFFF, 0, 65, 0xFFFF, 1, 1, 4, 0, 1, 0);
        $cmap = new UnicodeCMap(new BinaryReader(pack('nnnnN', 0, 1, 3, 1, 12) . $subtable), 6);
        self::assertSame(2, $cmap->glyphId(65));
        self::assertSame(0, $cmap->glyphId(66));
        self::assertSame(0, $cmap->glyphId(0xFFFF));
        self::assertSame(0, $cmap->glyphId(0x1F642));
    }

    public function testFormat12RejectsOverflowingGroupCountAndGlyphIds(): void
    {
        $prefix = pack('nnnnN', 0, 1, 3, 10, 12);
        foreach ([pack('nnNNN', 12, 0, 16, 0, 0xFFFFFFFF), pack('nnNNNNNN', 12, 0, 28, 0, 1, 65, 66, 5)] as $subtable) {
            try {
                new UnicodeCMap(new BinaryReader($prefix . $subtable), 6);
                self::fail('Invalid cmap groups must fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCyclicCompositeGlyphAndTruncatedPointDataAreRejected(): void
    {
        $composite = pack('n*', 0xFFFF, 0, 0, 10, 10, 3, 0, 0, 0);
        $simple = pack('n*', 1, 0, 0, 10, 10, 0, 0) . "\x01";
        foreach ([$composite, $simple] as $bytes) {
            try {
                new GlyphOutlines(new BinaryReader($bytes), new BinaryReader(pack('NN', 0, strlen($bytes))), 1, 1);
                self::fail('Invalid glyph graph or coordinates must fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testToUnicodeSplitsMappingsIntoBlocksOfAtMostOneHundred(): void
    {
        $utf32 = '';
        for ($point = 0x100; $point < 0x180; ++$point) {
            $utf32 .= pack('N', $point);
        }
        $utf8 = iconv('UTF-32BE', 'UTF-8', $utf32);
        self::assertIsString($utf8);
        $map = new CharacterMap($this->program(), new UnicodeText($utf8));
        $unicode = new ToUnicodeCMap($map);
        self::assertStringContainsString('100 beginbfchar', $unicode->data);
        self::assertStringContainsString('28 beginbfchar', $unicode->data);
        self::assertSame(2, substr_count($unicode->data, 'endbfchar'));
        self::assertStringContainsString('<0080> <017F>', $unicode->data);
    }

    public function testDecodedFontStreamsRejectAnAlreadyEncodedDataMarker(): void
    {
        $program = $this->program();
        $map = $this->map($program);
        $filter = new EncodedData(FilterName::FlateDecode);
        $constructors = [
            fn (): FontFile2 => new FontFile2(new CIDFontProgram($program), $filter),
            fn (): CIDToGIDMap => new CIDToGIDMap($map, $filter),
            fn (): ToUnicodeCMap => new ToUnicodeCMap($map, $filter),
        ];
        foreach ($constructors as $construct) {
            try {
                $construct();
                self::fail('Raw font streams cannot pretend to be pre-encoded.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEmptyAndHeaderOnlyGlyphsAreValid(): void
    {
        $header = pack('n*', 0, 0, 0, 0, 0);
        $outlines = new GlyphOutlines(new BinaryReader($header), new BinaryReader(pack('NNN', 0, 0, 10)), 1, 2);
        self::assertNull($outlines->boundingBox(0));
        self::assertSame([0, 0, 0, 0], $outlines->boundingBox(1));
    }

    public function testFontAndTextSizeLimitsFailBeforeParsing(): void
    {
        try {
            new FontProgram(str_repeat("\x00", (new FontProgramLimits())->maxBytes + 1));
            self::fail('Font size limit must be enforced.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(\InvalidArgumentException::class);
        new UnicodeText(str_repeat('A', 1048577));
    }

    public function testDuplicateAndOverlappingSfntDirectoryEntriesAreRejected(): void
    {
        $bytes = $this->bytes();
        $reader = new BinaryReader($bytes);
        foreach ([substr_replace($bytes, $reader->slice(12, 4), 28, 4), substr_replace($bytes, $reader->slice(20, 4), 36, 4)] as $invalid) {
            try {
                new FontProgram($invalid);
                self::fail('Invalid directory entries must fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFlagsAndStemMetricsRejectReservedOrConflictingValues(): void
    {
        foreach ([0, 36, 16 | 4, 0x80000000 | 4] as $flags) {
            try {
                new FontDescriptorFlags($flags);
                self::fail('Font descriptor flags must reject invalid bits.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new FontDescriptor(new FontFile2(new CIDFontProgram($this->program())), new FontDescriptorFlags(4), new NumericType(-1));
    }

    public function testMalformedFormat4RangesAndGlyphReferencesAreRejected(): void
    {
        $prefix = pack('nnnnN', 0, 1, 3, 1, 12);
        $valid = pack('n*', 4, 36, 0, 4, 4, 1, 0, 66, 0xFFFF, 0, 65, 0xFFFF, 1, 1, 4, 0, 1, 0);
        $invalidTables = [
            substr_replace($valid, pack('n', 3), 28, 2),
            substr_replace($valid, pack('n', 0xFFFE), 28, 2),
            substr_replace($valid, pack('n', 5), 32, 2),
            substr_replace($valid, pack('n', 65), 22, 2),
        ];
        foreach ($invalidTables as $subtable) {
            try {
                new UnicodeCMap(new BinaryReader($prefix . $subtable), 6);
                self::fail('Invalid cmap format 4 must fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCompositeGlyphDepthIsBounded(): void
    {
        $bytes = '';
        $offsets = [0];
        for ($glyph = 0; $glyph < 66; ++$glyph) {
            $bytes .= $glyph === 65 ? pack('n*', 0, 0, 0, 0, 0) : pack('n*', 0xFFFF, 0, 0, 10, 10, 3, $glyph + 1, 0, 0);
            $offsets[] = strlen($bytes);
        }
        $this->expectException(\InvalidArgumentException::class);
        new GlyphOutlines(new BinaryReader($bytes), new BinaryReader(pack('N*', ...$offsets)), 1, 66);
    }

    public function testFormat12RetainsCompactBinaryStorageForLargeAliasMaps(): void
    {
        $count = 100000;
        $groups = '';
        for ($point = 0; $point < $count; ++$point) {
            $groups .= pack('NNN', 0x10000 + $point, 0x10000 + $point, 1);
        }
        $bytes = pack('nnnnN', 0, 1, 3, 10, 12) . pack('nnNNN', 12, 0, 16 + strlen($groups), 0, $count) . $groups;
        $before = memory_get_usage();
        $cmap = new UnicodeCMap(new BinaryReader($bytes), 2);
        // The table remains binary. An array per group would allocate about 40 MiB.
        self::assertLessThan(strlen($bytes) * 3, memory_get_usage() - $before);
        self::assertSame(1, $cmap->glyphId(0x10000 + $count - 1));
        self::assertSame(0, $cmap->glyphId(0x10000 + $count));
    }

    public function testGlyphValidationUsesCompactStorageForLargeCompositeGraphs(): void
    {
        $count = 40000;
        $simple = pack('n*', 0, 0, 0, 0, 0);
        $component = pack('n*', 0xFFFF, 0, 0, 10, 10, 3, 0, 0, 0);
        $glyf = $simple . str_repeat($component, $count - 1);
        $loca = pack('N', 0);
        for ($glyph = 1; $glyph <= $count; ++$glyph) {
            $loca .= pack('N', 10 + ($glyph - 1) * 18);
        }
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $outlines = new GlyphOutlines(new BinaryReader($glyf), new BinaryReader($loca), 1, $count);
        self::assertLessThan(4 * (strlen($glyf) + strlen($loca)), memory_get_peak_usage() - $before);
        self::assertSame([0, 0, 10, 10], $outlines->boundingBox($count - 1));
    }

    public function testStandaloneReadersRejectInvalidGlyphCountsBeforeArithmetic(): void
    {
        foreach ([0, 65536, PHP_INT_MAX] as $count) {
            $reader = new BinaryReader('');
            foreach ([
                fn (): GlyphOutlines => new GlyphOutlines($reader, $reader, 1, $count),
                fn (): UnicodeCMap => new UnicodeCMap($reader, $count),
                fn (): HorizontalMetrics => new HorizontalMetrics($reader, $count, 1, 1000),
            ] as $construct) {
                try {
                    $construct();
                    self::fail('Invalid glyph count must fail before bounds arithmetic.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function testUnicodeCmapRejectsSurrogateCharacterRanges(): void
    {
        $bytes = pack('nnnnN', 0, 1, 3, 10, 12) . pack('nnNNNNNN', 12, 0, 28, 0, 1, 0xD800, 0xD800, 1);
        $this->expectException(\InvalidArgumentException::class);
        new UnicodeCMap(new BinaryReader($bytes), 2);
    }

    public function testApplicationCanSupplyAnExplicitFontProgramByteBudget(): void
    {
        self::assertSame(33554432, (new FontProgramLimits())->maxBytes);
        $bytes = $this->bytes();
        $budget = new FontProgramLimits(strlen($bytes));
        self::assertSame($budget, (new FontProgram($bytes, $budget))->limits);
        $this->expectException(\InvalidArgumentException::class);
        new FontProgram($bytes, new FontProgramLimits(strlen($bytes) - 1));
    }

    public function testZeroFontBudgetIsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontProgramLimits(0);
    }

    public function testNestedBinaryViewsCannotReadPastTheirOwnTable(): void
    {
        $reader = new BinaryReader("\x01\x02\x03\x04\x05\x06");
        $view = $reader->view(1, 4)->view(1, 2);
        self::assertSame(2, $view->length());
        self::assertSame(0x0304, $view->uint16(0));
        $this->expectException(\InvalidArgumentException::class);
        $view->view(1, 2);
    }

    public function testLargeBinaryViewsDoNotCopyTheUnderlyingBytes(): void
    {
        $bytes = str_repeat('A', 4194304);
        $before = memory_get_usage();
        $reader = new BinaryReader($bytes);
        $view = $reader->view(16, strlen($bytes) - 32);
        self::assertLessThan(65536, memory_get_usage() - $before);
        self::assertSame(strlen($bytes) - 32, $view->length());
        self::assertSame('AAAA', $view->slice(0, 4));
    }
}
