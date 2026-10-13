<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Fonts\CharacterMap;
use PDF\Document\Fonts\CharacterMapping;
use PDF\Document\Fonts\CIDFontType2;
use PDF\Document\Fonts\CIDToGIDMap;
use PDF\Document\Fonts\CIDWidths;
use PDF\Document\Fonts\FontDescriptor;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\Fonts\FontFile2;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Document\Fonts\Type0Font;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\FilterName;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** These tests construct PDF objects from explicit values, without any font parser. */
final class EmbeddedFontObjectsTest extends TestCase
{
    private function descriptor(FontFile2 $file, string $name = 'ExplicitFont'): FontDescriptor
    {
        return new FontDescriptor(
            $file,
            new NameType($name),
            new FontDescriptorFlags(4),
            new Rectangle(new NumericType(-20), new NumericType(-200), new NumericType(900), new NumericType(800)),
            new NumericType(0),
            new NumericType(800),
            new NumericType(-200),
            new NumericType(700),
            new NumericType(80),
        );
    }

    private function map(FontFile2 $file): CharacterMap
    {
        return new CharacterMap(
            $file,
            new NameType('ExplicitFont'),
            new NumericType(500),
            new CharacterMapping(0x41, 1, 2, new NumericType(500)),
            new CharacterMapping(0x391, 2, 2, new NumericType(500)),
            new CharacterMapping(0x1F642, 3, 4, new NumericType(625)),
        );
    }

    public function testPdfFontGraphIsBuiltOnlyFromExplicitPdfValues(): void
    {
        $file = new FontFile2('opaque supplied program bytes', new FlateDecode());
        $map = $this->map($file);
        $descriptor = $this->descriptor($file);
        $cid = new CIDFontType2($descriptor, new CIDToGIDMap($map), new CIDWidths($map));
        $font = new Type0Font($cid, new ToUnicodeCMap($map));
        $context = new SerializationContext();
        $context->prepare($font);
        self::assertSame($file->data, gzuncompress($file->bytes($context)));
        self::assertSame(strlen($file->data), $file->dictionary()->get('Length1')?->value);
        self::assertStringContainsString('/FontBBox [-20 -200 900 800]', $descriptor->toPdf($context));
        self::assertStringContainsString('/Ascent 800', $descriptor->toPdf($context));
        self::assertStringContainsString('/BaseFont /ExplicitFont', $cid->toPdf($context));
        self::assertStringContainsString('/Encoding /Identity-H', $font->toPdf($context));
        self::assertInstanceOf(IndirectReference::class, $font->get('ToUnicode'));
        self::assertInstanceOf(IndirectReference::class, $descriptor->get('FontFile2'));
    }

    public function testExplicitCidGapsPreserveGlyphIndicesAndWidths(): void
    {
        $file = new FontFile2('supplied bytes');
        $map = new CharacterMap(
            $file,
            new NameType('ExplicitFont'),
            new NumericType(500),
            new CharacterMapping(66, 8, 3, new NumericType(600)),
            new CharacterMapping(65, 2, 2, new NumericType(550)),
        );
        self::assertSame([2, 8], array_map(static fn (CharacterMapping $mapping): int => $mapping->cid, $map->mappings));
        self::assertSame('[0 [500] 2 [550] 8 [600]]', (string) new CIDWidths($map));
        self::assertSame("\x00\x00\x00\x00\x00\x02" . str_repeat("\x00\x00", 5) . "\x00\x03", (new CIDToGIDMap($map))->data);
        self::assertStringContainsString('<0008> <0042>', (new ToUnicodeCMap($map))->data);
    }

    public function testUnicodeAliasesPreserveSeparateCharacterIdentities(): void
    {
        $map = $this->map(new FontFile2('supplied bytes'));
        self::assertSame($map->character(65)->glyphId, $map->character(0x391)->glyphId);
        self::assertNotSame($map->character(65)->cid, $map->character(0x391)->cid);
        self::assertStringContainsString('<0002> <0391>', (new ToUnicodeCMap($map))->data);
        self::assertStringContainsString('<0003> <D83DDE42>', (new ToUnicodeCMap($map))->data);
    }

    public function testLargestExplicitCidIsBoundedAndAddressable(): void
    {
        $map = new CharacterMap(new FontFile2('supplied bytes'), new NameType('ExplicitFont'), new NumericType(0), new CharacterMapping(65, 65535, 65535, new NumericType(500)));
        $glyphMap = new CIDToGIDMap($map);
        self::assertSame(131072, strlen($glyphMap->data));
        self::assertSame("\xFF\xFF", substr($glyphMap->data, -2));
        self::assertSame('[0 [0] 65535 [500]]', (string) new CIDWidths($map));
    }

    public function testUnicodeAndCidIdentitiesMustBeUnique(): void
    {
        $file = new FontFile2('supplied bytes');
        foreach ([[65, 2], [66, 1]] as [$point, $cid]) {
            try {
                new CharacterMap($file, new NameType('ExplicitFont'), new NumericType(0), new CharacterMapping(65, 1, 2, new NumericType(500)), new CharacterMapping($point, $cid, 3, new NumericType(500)));
                self::fail('Duplicate identities must fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function invalidMappings(): iterable
    {
        yield 'negative Unicode' => [-1, 1, 1, 500];
        yield 'surrogate' => [0xD800, 1, 1, 500];
        yield 'beyond Unicode' => [0x110000, 1, 1, 500];
        yield 'reserved CID 0' => [65, 0, 1, 500];
        yield 'CID overflow' => [65, 65536, 1, 500];
        yield 'notdef glyph' => [65, 1, 0, 500];
        yield 'GID overflow' => [65, 1, 65536, 500];
        yield 'negative width' => [65, 1, 1, -1];
    }

    #[DataProvider('invalidMappings')]
    public function testInvalidMappingsAreRejected(int $point, int $cid, int $glyph, int $width): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CharacterMapping($point, $cid, $glyph, new NumericType($width));
    }

    public function testDescriptorAndRepertoireMustShareTheEmbeddedFontAndName(): void
    {
        $file = new FontFile2('supplied bytes');
        $map = $this->map($file);
        foreach ([$this->descriptor(new FontFile2('supplied bytes')), $this->descriptor($file, 'OtherFont')] as $descriptor) {
            try {
                new CIDFontType2($descriptor, new CIDToGIDMap($map), new CIDWidths($map));
                self::fail('Unrelated font metadata must fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testWidthsAndUnicodeMustShareTheRepertoire(): void
    {
        $file = new FontFile2('supplied bytes');
        $map = $this->map($file);
        $other = $this->map($file);
        $descriptor = $this->descriptor($file);
        try {
            new CIDFontType2($descriptor, new CIDToGIDMap($map), new CIDWidths($other));
            self::fail('Unrelated widths must fail.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $cid = new CIDFontType2($descriptor, new CIDToGIDMap($map), new CIDWidths($map));
        $this->expectException(\InvalidArgumentException::class);
        new Type0Font($cid, new ToUnicodeCMap($other));
    }

    public function testManagedEntriesRemainImmutableAndProtected(): void
    {
        $file = new FontFile2('supplied bytes');
        $map = $this->map($file);
        $descriptor = $this->descriptor($file);
        $cid = new CIDFontType2($descriptor, new CIDToGIDMap($map), new CIDWidths($map));
        $font = new Type0Font($cid, new ToUnicodeCMap($map));
        $extended = $font->with(new Entry(new NameType('PrivateTag'), new NameType('Example')));
        self::assertFalse($font->has('PrivateTag'));
        self::assertTrue($extended->has('PrivateTag'));
        foreach ([[$descriptor, 'FontFile3'], [$cid, 'W2'], [$font, 'ToUnicode']] as [$object, $key]) {
            try {
                $object->without($key);
                self::fail('Managed entries must be protected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDecodedStreamsCannotBeDeclaredAlreadyEncoded(): void
    {
        $map = $this->map(new FontFile2('supplied bytes'));
        $filter = new EncodedData(FilterName::FlateDecode);
        foreach ([fn (): FontFile2 => new FontFile2('supplied bytes', $filter), fn (): CIDToGIDMap => new CIDToGIDMap($map, $filter), fn (): ToUnicodeCMap => new ToUnicodeCMap($map, $filter)] as $create) {
            try {
                $create();
                self::fail('Decoded font streams cannot use EncodedData.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testNegativeNotdefWidthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CharacterMap(new FontFile2('supplied bytes'), new NameType('ExplicitFont'), new NumericType(-1));
    }

    public function testUnknownUnicodeCharacterDoesNotAlterTheRepertoire(): void
    {
        $map = $this->map(new FontFile2('supplied bytes'));
        $this->expectException(\OutOfRangeException::class);
        $map->character(66);
    }
}
