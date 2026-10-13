<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\DataStructure\ByteString;
use PDF\Syntax\DataStructure\PDFDocEncodedString;
use PDF\Syntax\DataStructure\PDFDocEncoding;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PDFStringEncodingTest extends TestCase
{
    public function testEveryDefinedByteRoundTripsAndUndefinedCodesAreExcluded(): void
    {
        $codec = new PDFDocEncoding();
        $defined = 0;
        for ($byte = 0; $byte < 256; ++$byte) {
            if ($codec->defined($byte)) {
                self::assertSame(chr($byte), $codec->encode($codec->decode(chr($byte))));
                ++$defined;
            }
        }
        self::assertSame(232, $defined);
        self::assertFalse($codec->defined(-1));
        self::assertFalse($codec->defined(256));
    }

    public function testTableD2SpecialCharactersAndControlWhitespaceAreExact(): void
    {
        self::assertSame(
            "\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x80\x8a\x93\x94\x95\x96\x9e\xa0\xe9\xb5\t\r\n",
            (new PDFDocEncoding())->encode('˘ˇˆ˙˝˛˚˜•−ﬁﬂŁŒž€éµ' . "\t\r\n")
        );
    }

    /** @return iterable<string, array{int}> */
    public static function undefinedBytes(): iterable
    {
        foreach ([...range(0, 8), 11, 12, ...range(14, 23), 127, 159, 173] as $byte) {
            yield (string) $byte => [$byte];
        }
    }

    #[DataProvider('undefinedBytes')]
    public function testUndefinedPdfDocBytesDoNotAcquireAnInventedMeaning(int $byte): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PDFDocEncoding())->decode(chr($byte));
    }

    public function testPdfDocTextEncodingAndUtf16FallbackAreDistinct(): void
    {
        self::assertSame("Fran\xe7ais \xa0", (new TextString('Français €'))->value);
        self::assertSame("\xfe\xff\x03\xa9", (new TextString('Ω'))->value);
        self::assertSame("\xfe\xff\x00\x00", (new TextString("\0"))->value);
        self::assertSame("\xfe\xff\x00\xfe\x00\xff", (new TextString('þÿ'))->value);
        self::assertSame('þÿ', (new TextStringDecoder())->decode(new TextString('þÿ')));
        self::assertSame('é🙂', (new TextStringDecoder())->decode(new TextString('é🙂')));
        self::assertSame('€', (new TextStringDecoder())->decode(new LiteralString("\xa0")));
    }

    public function testStringKindsDoNotTranscodeByteOrAsciiStrings(): void
    {
        self::assertSame("\0\xff\xad", (new ByteString("\0\xff\xad"))->value);
        self::assertSame("\0ASCII\x7f", (new AsciiString("\0ASCII\x7f"))->value);
        self::assertSame('é€', (new PDFDocEncodedString("\xe9\xa0"))->text);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUtf16(): iterable
    {
        yield 'odd' => ["\xfe\xff\0"];
        yield 'unpaired high' => ["\xfe\xff\xd8\0"];
        yield 'unpaired low' => ["\xfe\xff\xdc\0"];
        yield 'two highs' => ["\xfe\xff\xd8\0\xd8\0"];
    }

    #[DataProvider('invalidUtf16')]
    public function testInvalidUtf16TextIsRejected(string $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TextStringDecoder())->decode(new LiteralString($bytes));
    }

    public function testAsciiRejectsHighBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AsciiString("\x80");
    }

    public function testPdfDocEncoderRejectsInvalidUtf8(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PDFDocEncoding())->encode("\xff");
    }

    public function testPdfDocEncoderRejectsMissingUnicodeCharacter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PDFDocEncoding())->encode('Ω');
    }
}
