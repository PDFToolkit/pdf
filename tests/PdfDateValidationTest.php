<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\PdfDateValidation;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\DataStructure\Utf8TextStringEncoding;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfDateValidationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validDates(): iterable
    {
        foreach ([
            'D:2026', 'D:202610', 'D:20261014', 'D:2026101412', 'D:202610141230', 'D:20261014123059',
            'D:20000229', 'D:20240229', 'D:19000228', 'D:20260430', 'D:20261231',
            'D:20261014123059Z', "D:20261014123059+23'59", "D:20261014123059-23'59",
            "D:20261014123059+02'30'", "D:20261014123059-05'00'", 'D:20261014123059+02', "D:20261014123059+02'",
            'D:20261014123059Z00', "D:20261014123059Z00'00", "D:20261014123059Z00'00'",
        ] as $value) {
            yield $value => [$value];
        }
    }

    #[DataProvider('validDates')]
    public function testSuccessiveComponentsCalendarAndOffsetsPreserveTheExactValue(string $value): void
    {
        $date = new LiteralString($value);
        (new PdfDateValidation())->validate($date);
        self::assertSame($value, $date->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDates(): iterable
    {
        foreach ([
            'not-a-date', '20261014', 'D:', 'D:202', 'D:20261', 'D:2026101', 'D:2026-10-14',
            'D:202600', 'D:202613', 'D:20261000', 'D:20261032', 'D:20010229', 'D:19000229', 'D:20260431',
            'D:20261014240000', 'D:20261014236000', 'D:20261014235960',
            'D:2026Z', 'D:20261014Z', "D:202610141230+02'00", "D:20261014123000+24'00", "D:20261014123000-00'60",
            'D:20261014123000Z01', "D:20261014123000Z00'01", 'D:20261014123000+0230', "D:20261014123000+02''", "D:20261014123000+02'00''",
            '(D:20261014)', ' D:20261014', "D:20261014\n", "D:20261014\0", 'D:２０２６',
        ] as $index => $value) {
            yield 'invalid PDF date' . $index => [$value];
        }
    }

    #[DataProvider('invalidDates')]
    public function testMalformedAndImpossiblePdfDatesAreRejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PdfDateValidation())->validate(new LiteralString($value));
    }

    /** @return iterable<string, array{string}> */
    public static function nativeDates(): iterable
    {
        yield 'UTC' => ['2026-10-14T12:30:59Z'];
        yield 'positive offset' => ['2026-10-14T12:30:59+02:30'];
        yield 'negative offset' => ['2026-10-14T12:30:59-05:00'];
        yield 'Gregorian leap century' => ['2000-02-29T00:00:00Z'];
    }

    #[DataProvider('nativeDates')]
    public function testExistingNativeDateApiRetainsItsOwnTimezoneAndLegacyApostrophe(string $timestamp): void
    {
        $dateTime = new \DateTimeImmutable($timestamp);
        $date = new Date($dateTime);
        $before = $date->value;
        (new PdfDateValidation(new TextStringDecoder(PdfVersion::PDF20)))->validate($date);
        self::assertSame($before, $date->value);
        self::assertSame($dateTime, $date->dateTime);
    }

    public function testTextEncodingsAndPdfStringSyntaxDoNotChangeTheDateValue(): void
    {
        $text = "D:20261014123059+23'59'";
        $validator = new PdfDateValidation(new TextStringDecoder(PdfVersion::PDF20));
        $validator->validate(new HexadecimalString($text));
        $validator->validate(new LiteralString("\xfe\xff" . iconv('UTF-8', 'UTF-16BE', $text)));
        $validator->validate(new TextString($text, new Utf8TextStringEncoding()));
        self::assertSame(23, strlen($text));
    }

    public function testUtf8DateEncodingUsesTheExplicitPdf20DecoderContext(): void
    {
        $date = new TextString('D:20261014', new Utf8TextStringEncoding());
        (new PdfDateValidation(new TextStringDecoder(PdfVersion::PDF20)))->validate($date);
        $this->expectException(\InvalidArgumentException::class);
        (new PdfDateValidation())->validate($date);
    }

    public function testEncodedInputBoundRejectsLongDataBeforeDecodingOrRegex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bounded date-string');
        (new PdfDateValidation())->validate(new LiteralString("\xfe\xff" . str_repeat('9', 100000)));
    }

    public function testInvalidUnicodeRepresentationCannotBecomeAPdfDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PdfDateValidation())->validate(new LiteralString("\xfe\xff\x00"));
    }
}
