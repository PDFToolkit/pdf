<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecuritySerializationTest extends TestCase
{
    public function testTextCannotCloseItsOperandOrIntroducePdfObjects(): void
    {
        $payload = ") Tj ET\nendstream\nendobj\n99 0 obj << /S /JavaScript /JS (alert(1)) >>";
        $content = new ContentStreams(
            new Op\BeginTextObject(),
            new Op\SetTextFontAndSize(new NameType('F1'), new IntegerType(12)),
            new Op\ShowText(new LiteralString($payload)),
            new Op\EndTextObject(),
        );

        self::assertSame(
            "BT\n/F1 12 Tf\n"
            . '(\) Tj ET\nendstream\nendobj\n99 0 obj << /S /JavaScript /JS \(alert\(1\)\) >>)'
            . " Tj\nET\n",
            (string) $content,
        );
    }

    public function testDictionaryNameCannotIntroduceOtherEntries(): void
    {
        $dictionary = new DictionaryType(
            new Entry(new NameType("Slot /Length 0 /AA << /S /JavaScript >>#\n"), new LiteralString('safe')),
            new Entry(new NameType('After'), new IntegerType(7)),
        );

        self::assertSame(
            '<< /Slot#20#2FLength#200#20#2FAA#20#3C#3C#20#2FS#20#2FJavaScript#20#3E#3E#23#0A (safe) /After 7 >>',
            (string) $dictionary,
        );
        self::assertNull($dictionary->get('AA'));
        self::assertCount(2, $dictionary);
    }

    public function testAllByteValuesMatchIndependentlyCheckedEncodingVectors(): void
    {
        $bytes = implode('', array_map(chr(...), range(0, 255)));
        $literal = (string) new LiteralString($bytes);

        // These fixed vectors were checked by decoding the strings with pypdf.
        self::assertSame('d5b184157c2f5695d5bfe2445ebc16a7f4023fae82de58bdf72ca6fe52e216db', hash('sha256', $literal));
        self::assertSame(
            '4d8d6cc192e3173bb9b3e24a65a1c919725b72f5d633e6ced8b6110ba1ab3ff8',
            hash('sha256', (string) new HexadecimalString($bytes)),
        );
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F-\xFF]/', $literal);
        // Names exclude the null byte, and encode the remaining 255 values.
        self::assertSame(
            '8891651131e0c976d29dc2b37d318eee9f0d1229579fc54de456512e035adc59',
            hash('sha256', (string) new NameType(substr($bytes, 1))),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function inlineImageTerminatorPayloads(): iterable
    {
        yield 'reader accepts EI before Q without preceding whitespace' => ['xEI Q BT /F1 12 Tf (INJECTED) Tj ET q'];
        yield 'reader accepts EI before EMC without preceding whitespace' => ['xEI EMC'];
        yield 'delimiter immediately before EI' => ['>EI Q'];
        yield 'EI at the end joins the serializer newline' => ['xEI'];
    }

    #[DataProvider('inlineImageTerminatorPayloads')]
    public function testInlineImageBytesCannotBecomeContentOperators(string $payload): void
    {
        $dictionary = new DictionaryType(
            new Entry(new NameType('W'), new IntegerType(1)),
            new Entry(new NameType('H'), new IntegerType(1)),
            new Entry(new NameType('BPC'), new IntegerType(8)),
            new Entry(new NameType('CS'), new NameType('G')),
            new Entry(new NameType('F'), new NameType('Fl')),
        );

        $this->expectException(\InvalidArgumentException::class);
        new Op\InlineImage($dictionary, $payload);
    }
}
