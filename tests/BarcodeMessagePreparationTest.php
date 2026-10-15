<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Barcodes\BarcodeDataPreparation;
use PDF\Document\Barcodes\BarcodeEncodingRequest;
use PDF\Document\Barcodes\BarcodeLimits;
use PDF\Document\Barcodes\BarcodeMessage;
use PDF\Document\Barcodes\BarcodeMessagePartition;
use PDF\Document\Barcodes\BarcodeWidgetParameters;
use PDF\Document\Forms\BarcodePaperMetadata;
use PDF\Document\Forms\BarcodeSymbology;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\Utf16BETextStringEncoding;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Filters\TiffPredictor;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BarcodeMessagePreparationTest extends TestCase
{
    private function metadata(): BarcodePaperMetadata
    {
        return new BarcodePaperMetadata(BarcodeSymbology::QrCode, new NumericType(1), new NumericType(1), new IntegerType(1), errorCorrection: new IntegerType(0));
    }

    private function rectangle(int|float $left = 10, int|float $bottom = 20, int|float $right = 210, int|float $top = 120): Rectangle
    {
        return new Rectangle(new NumericType($left), new NumericType($bottom), new NumericType($right), new NumericType($top));
    }

    public function testUncompressedPartsPreserveAllBytesAndExposeTheirOrderAndOffsets(): void
    {
        $bytes = implode('', array_map(chr(...), range(0, 255))) . "\xfe\xff\0\\()";
        $value = new LiteralString($bytes);
        $partition = new BarcodeMessagePartition($value, new BarcodeDataPreparation(), 7);
        self::assertSame($value, $partition->value);
        self::assertSame($value, $partition->prepared);
        self::assertSame($bytes, implode('', array_map(static fn (BarcodeMessage $part) => $part->value->value, $partition->messages)));
        $sizes = [];
        $offset = 0;
        foreach ($partition->messages as $index => $part) {
            self::assertSame($index, $part->index);
            self::assertSame(7, $part->total);
            self::assertSame($offset, $part->offset);
            $sizes[] = strlen($part->value->value);
            $offset += strlen($part->value->value);
        }
        self::assertSame(strlen($bytes), $offset);
        self::assertLessThanOrEqual(1, max($sizes) - min($sizes));
    }

    public function testRemainderGoesToTheEarlierWidgetInsteadOfChangingWidgetOrder(): void
    {
        $partition = new BarcodeMessagePartition(new LiteralString('abcdefgh'), new BarcodeDataPreparation(), 3);
        self::assertSame(['abc', 'def', 'gh'], array_map(static fn (BarcodeMessage $part) => $part->value->value, $partition->messages));
        self::assertSame([0, 3, 6], array_map(static fn (BarcodeMessage $part) => $part->offset, $partition->messages));
    }

    public function testOneZlibStreamIsCompressedBeforeAnyPartIsMade(): void
    {
        $value = new HexadecimalString(str_repeat("\0(payload)\xff", 150));
        $preparation = new BarcodeDataPreparation(new IntegerType(1), new FlateDecode(9));
        $partition = new BarcodeMessagePartition($value, $preparation, 4);
        $joined = implode('', array_map(static fn (BarcodeMessage $part) => $part->value->value, $partition->messages));
        self::assertSame($preparation->prepare($value)->value, $joined);
        self::assertSame($value->value, gzuncompress($joined));
        self::assertSame("\x78", $joined[0]);
        self::assertLessThanOrEqual(1, strlen($partition->messages[0]->value->value) - strlen($partition->messages[3]->value->value));
    }

    public function testTextStringBomIsDataAndMayBeSplitBetweenBinaryMessages(): void
    {
        $value = new TextString('A', new Utf16BETextStringEncoding());
        self::assertSame("\xfe\xff\0A", $value->value);
        $partition = new BarcodeMessagePartition($value, new BarcodeDataPreparation(), 3);
        self::assertSame(["\xfe\xff", "\0", 'A'], array_map(static fn (BarcodeMessage $part) => $part->value->value, $partition->messages));
        $compressed = new BarcodeMessagePartition($value, new BarcodeDataPreparation(new IntegerType(1)), 3);
        self::assertSame($value->value, gzuncompress(implode('', array_map(static fn (BarcodeMessage $part) => $part->value->value, $compressed->messages))));
    }

    public function testEmptyValuesStillProduceOneMessageForEveryWidget(): void
    {
        foreach ([1, 4] as $count) {
            $partition = new BarcodeMessagePartition(new LiteralString(''), new BarcodeDataPreparation(), $count);
            self::assertCount($count, $partition->messages);
            foreach ($partition->messages as $part) {
                self::assertSame('', $part->value->value);
                self::assertSame(0, $part->offset);
            }
        }
        $compressed = new BarcodeMessagePartition(new LiteralString(''), new BarcodeDataPreparation(new IntegerType(1)), 12);
        self::assertCount(12, $compressed->messages);
        self::assertSame('', gzuncompress(implode('', array_map(static fn (BarcodeMessage $part) => $part->value->value, $compressed->messages))));
        self::assertSame('', $compressed->messages[11]->value->value);
    }

    public function testMoreWidgetsThanBytesDoNotDropEmptyMessages(): void
    {
        $parts = (new BarcodeMessagePartition(new LiteralString('ab'), new BarcodeDataPreparation(), 4))->messages;
        self::assertSame(['a', 'b', '', ''], array_map(static fn (BarcodeMessage $part) => $part->value->value, $parts));
        self::assertSame([0, 1, 2, 2], array_map(static fn (BarcodeMessage $part) => $part->offset, $parts));
    }

    /** @return iterable<string,array{int, BarcodeLimits, string}> */
    public static function exceededBudgets(): iterable
    {
        yield 'no widgets' => [0, new BarcodeLimits(), 'abc'];
        yield 'negative widgets' => [-1, new BarcodeLimits(), 'abc'];
        yield 'too many widgets' => [3, new BarcodeLimits(maximumWidgets: 2), 'abc'];
        yield 'input' => [1, new BarcodeLimits(maximumInputBytes: 2), 'abc'];
        yield 'prepared' => [1, new BarcodeLimits(maximumPreparedBytes: 2), 'abc'];
        yield 'native maximum count' => [PHP_INT_MAX, new BarcodeLimits(), ''];
    }

    #[DataProvider('exceededBudgets')]
    public function testBudgetsRejectBeforePartitionAllocation(int $count, BarcodeLimits $limits, string $value): void
    {
        $this->expectException(\LengthException::class);
        new BarcodeMessagePartition(new LiteralString($value), new BarcodeDataPreparation(), $count, $limits);
    }

    public function testCompressedSizeIsCheckedBeforeMessagesAreAllocated(): void
    {
        $this->expectException(\LengthException::class);
        new BarcodeMessagePartition(new LiteralString(''), new BarcodeDataPreparation(new IntegerType(1)), 2, new BarcodeLimits(maximumPreparedBytes: 1));
    }

    public function testZeroBudgetIsNotAnUnlimitedProcessingRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BarcodeLimits(maximumImageBytes: 0);
    }

    public function testDataPreparationCannotSilentlyApplyAnImagePredictor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BarcodeDataPreparation(new IntegerType(1), new FlateDecode(predictor: new TiffPredictor()));
    }

    public function testMessageIdentityAndOffsetsHaveTheirOwnBounds(): void
    {
        foreach ([[0, 0, 0], [-1, 1, 0], [1, 1, 0], [0, 1, -1], [0, 1, PHP_INT_MAX]] as [$index, $total, $offset]) {
            try {
                new BarcodeMessage(new LiteralString('x'), $index, $total, $offset);
                self::fail('Invalid message identity was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRequestUsesTheExactPartAndPmdThatTheWidgetWillSerialize(): void
    {
        $metadata = $this->metadata();
        $parameters = new BarcodeWidgetParameters($this->rectangle(), $metadata);
        $message = new BarcodeMessage(new HexadecimalString("\0\xff"), 1, 2, 3);
        $request = new BarcodeEncodingRequest($message, $parameters);
        self::assertSame($message, $request->message);
        self::assertSame($metadata, $request->widget->metadata);
        self::assertSame('QRCode', $request->widget->metadata->get('Symbology')->value);
        self::assertStringContainsString('/Symbology /QRCode', $metadata->toPdf(new SerializationContext()));
    }

    public function testOptionalPmdParametersCannotBypassConstructorValidationThroughEntry(): void
    {
        foreach (['ECC', 'XSymHeight', 'Symbology'] as $key) {
            try {
                new BarcodePaperMetadata(BarcodeSymbology::DataMatrix, new NumericType(1), new NumericType(1), new IntegerType(2), entries: new Entry(new NameType($key), new NumericType(1.5)));
                self::fail('A constructor-controlled PMD parameter was supplied through Entry.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $caption = new TextString('explicit caption');
        $metadata = new BarcodePaperMetadata(BarcodeSymbology::DataMatrix, new NumericType(1), new NumericType(1), new IntegerType(1), entries: new Entry(new NameType('Caption'), $caption));
        self::assertSame($caption, $metadata->get('Caption'));
    }

    public function testReversedRectangleCornersProducePositiveLocalAppearanceExtents(): void
    {
        $rectangle = $this->rectangle(210, 120, 10, 20);
        $parameters = new BarcodeWidgetParameters($rectangle, $this->metadata());
        self::assertSame($rectangle, $parameters->rectangle);
        self::assertSame(200, $parameters->width->value);
        self::assertSame(100, $parameters->height->value);
    }

    public function testZeroAreaIsRejectedByAppearancePreparationWithoutInvalidatingRectangleSyntax(): void
    {
        $rectangle = $this->rectangle(0, 0, 0, 20);
        self::assertSame('[0 0 0 20]', $rectangle->toPdf(new SerializationContext()));
        $this->expectException(\InvalidArgumentException::class);
        new BarcodeWidgetParameters($rectangle, $this->metadata());
    }

    public function testFiniteCoordinatesWhoseDifferenceOverflowsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BarcodeWidgetParameters($this->rectangle(-1.0e308, 0, 1.0e308, 20), $this->metadata());
    }
}
