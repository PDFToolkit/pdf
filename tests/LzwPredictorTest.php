<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Syntax\Filters\AdaptivePngFilterSelection;
use PDF\Syntax\Filters\EarlyChange;
use PDF\Syntax\Filters\FixedPngFilterSelection;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Filters\LZWDecode;
use PDF\Syntax\Filters\LzwEncodingTable;
use PDF\Syntax\Filters\NoPrediction;
use PDF\Syntax\Filters\PngPredictor;
use PDF\Syntax\Filters\PngRowFilter;
use PDF\Syntax\Filters\PredictorBitsPerComponent;
use PDF\Syntax\Filters\PredictorLayout;
use PDF\Syntax\Filters\TiffPredictor;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\DictionaryType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LzwPredictorTest extends TestCase
{
    public function testLzwMatchesTheExactIsoTable7AndExample2Vector(): void
    {
        foreach ([0, 1] as $value) {
            $encoder = new LZWDecode(new EarlyChange(new IntegerType($value)));
            self::assertSame('800b6050220c0c8501', bin2hex($encoder->encode('-----A---B')));
            self::assertSame('804040', bin2hex($encoder->encode('')));
            self::assertSame('800b6050220c0c8501', bin2hex($encoder->encode('-----A---B')));
            self::assertSame($value, $this->integerParameter($encoder->parameters(), 'EarlyChange'));
        }
    }

    public function testEarlyChangeHasOnlyItsTwoSourceValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EarlyChange(new IntegerType(2));
    }

    public function testCodeSizeBoundariesAndFinalEodHaveDifferentSynchronization(): void
    {
        foreach ([0, 1] as $early) {
            $table = new LzwEncodingTable(new EarlyChange(new IntegerType($early)));
            $prefix = 0;
            $byte = 0;
            for ($next = 258; $next <= 4095; ++$next) {
                $widthBefore = $table->bits()->integer();
                $full = $table->append($prefix, $byte);
                if (in_array($next + 1 + $early, [512, 1024, 2048], true)) {
                    self::assertSame($widthBefore + 1, $table->endMarkerBits()->integer());
                    self::assertSame($widthBefore, $table->bits()->integer());
                }
                if (in_array($next + $early, [512, 1024, 2048], true)) {
                    self::assertSame($widthBefore + 1, $table->bits()->integer());
                }
                if (in_array($next, [258, 510, 511, 512, 1022, 1023, 1024, 2046, 2047, 2048, 4094, 4095], true)) {
                    self::assertSame($next === 4095, $full);
                }
                ++$byte;
                if ($byte === 256) {
                    $byte = 0;
                    ++$prefix;
                }
            }
            $table->clear();
            self::assertSame(9, $table->bits()->integer());
            self::assertNull($table->lookup(0, 0));
        }
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidTablePairs(): iterable
    {
        yield 'negative prefix' => [-1, 0];
        yield 'clear marker as prefix' => [256, 0];
        yield 'EOD marker as prefix' => [257, 0];
        yield 'undefined sequence' => [258, 0];
        yield 'negative byte' => [0, -1];
        yield 'byte overflow alias' => [0, 256];
    }

    #[DataProvider('invalidTablePairs')]
    public function testInvalidCodePairsCannotAliasValidTableEntries(int $prefix, int $byte): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LzwEncodingTable(new EarlyChange()))->lookup($prefix, $byte);
    }

    /** @return iterable<string, array{PredictorBitsPerComponent, int, int, string, string}> */
    public static function tiffVectors(): iterable
    {
        yield 'one bit with nonzero row padding' => [PredictorBitsPerComponent::One, 1, 5, 'dd', 'b5'];
        yield 'two bits with nonzero row padding' => [PredictorBitsPerComponent::Two, 1, 3, 'db', 'e7'];
        yield 'four bits with nonzero row padding' => [PredictorBitsPerComponent::Four, 1, 3, 'a7f5', 'ad85'];
        yield 'four-bit interleaved components' => [PredictorBitsPerComponent::Four, 2, 3, '1928f1', '191fd9'];
        yield 'eight-bit RGB' => [PredictorBitsPerComponent::Eight, 3, 2, '0a141e28323c0f1e2d2d3c4b', '0a141e1e1e1e0f1e2d1e1e1e'];
        yield 'sixteen-bit big endian with wraparound' => [PredictorBitsPerComponent::Sixteen, 1, 3, '01000001ffff', '0100ff01fffe'];
        yield 'sixteen-bit resets at each row' => [PredictorBitsPerComponent::Sixteen, 1, 2, 'ffff0000ffff0000', 'ffff0001ffff0001'];
    }

    #[DataProvider('tiffVectors')]
    public function testTiffPredictsSamplesAndPreservesUnusedRowBits(PredictorBitsPerComponent $bits, int $colors, int $columns, string $raw, string $expected): void
    {
        $predictor = new TiffPredictor(new PredictorLayout(new IntegerType($colors), new IntegerType($columns), $bits));
        $bytes = hex2bin($raw);
        self::assertIsString($bytes);
        self::assertSame($expected, bin2hex($predictor->encode($bytes)));
        self::assertSame(2, $this->integerParameter($predictor->parameters(), 'Predictor'));
        self::assertSame($bits->value, $this->integerParameter($predictor->parameters(), 'BitsPerComponent'));
        self::assertSame($colors, $this->integerParameter($predictor->parameters(), 'Colors'));
        self::assertSame($columns, $this->integerParameter($predictor->parameters(), 'Columns'));
    }

    /** @return iterable<string, array{PngRowFilter, string}> */
    public static function pngVectors(): iterable
    {
        yield 'None' => [PngRowFilter::None, '000a141e28323c000f1e2d2d3c4b'];
        yield 'Sub' => [PngRowFilter::Sub, '010a141e1e1e1e010f1e2d1e1e1e'];
        yield 'Up' => [PngRowFilter::Up, '020a141e28323c02050a0f050a0f'];
        yield 'Average' => [PngRowFilter::Average, '030a141e23282d030a141e121417'];
        yield 'Paeth' => [PngRowFilter::Paeth, '040a141e1e1e1e04050a0f050a0f'];
    }

    #[DataProvider('pngVectors')]
    public function testEveryPngFilterWritesASelectorForEachByteBasedRow(PngRowFilter $filter, string $expected): void
    {
        $layout = new PredictorLayout(new IntegerType(3), new IntegerType(2));
        $predictor = new PngPredictor($layout, new FixedPngFilterSelection($filter));
        $bytes = hex2bin('0a141e28323c0f1e2d2d3c4b');
        self::assertIsString($bytes);
        self::assertSame($expected, bin2hex($predictor->encode($bytes)));
        self::assertSame(10 + $filter->value, $this->integerParameter($predictor->parameters(), 'Predictor'));
    }

    public function testPngSixteenBitPredictionOperatesOnBytesRatherThanSixteenBitSamples(): void
    {
        $layout = new PredictorLayout(new IntegerType(1), new IntegerType(3), PredictorBitsPerComponent::Sixteen);
        $predictor = new PngPredictor($layout, new FixedPngFilterSelection(PngRowFilter::Sub));
        $raw = hex2bin('01000001ffff');
        self::assertIsString($raw);
        self::assertSame('010100ff01fffe', bin2hex($predictor->encode($raw)));
    }

    public function testAdaptiveSelectionIsAnExplicitDeterministicObject(): void
    {
        $layout = new PredictorLayout(new IntegerType(1), new IntegerType(4));
        $selection = new AdaptivePngFilterSelection();
        self::assertSame(PngRowFilter::Up, $selection->select("\x0a\x14\x1e\x28", "\x0a\x14\x1e\x28", $layout));
        self::assertSame(PngRowFilter::None, $selection->select("\0\0\0\0", "\0\0\0\0", $layout));
        $predictor = new PngPredictor($layout, $selection);
        self::assertSame(15, $this->integerParameter($predictor->parameters(), 'Predictor'));
        self::assertSame('', $predictor->encode(''));
    }

    public function testPredictorOneHasNoInvalidSampleLayoutParameters(): void
    {
        $predictor = new NoPrediction();
        $raw = "arbitrary\0\xff bytes";
        self::assertSame($raw, $predictor->encode($raw));
        self::assertCount(1, $predictor->parameters());
        $this->expectException(\InvalidArgumentException::class);
        (new PredictorLayout())->parameters(new IntegerType(1));
    }

    public function testFlateAndLzwDeclareTheSameExplicitPredictionAppliedBeforeCompression(): void
    {
        $predictor = new TiffPredictor(new PredictorLayout(new IntegerType(1), new IntegerType(3)));
        $flate = new FlateDecode(6, $predictor);
        self::assertSame("\1\1\1", gzuncompress($flate->encode("\1\2\3")));
        $lzw = new LZWDecode(new EarlyChange(new IntegerType(0)), $predictor);
        self::assertSame(2, $this->integerParameter($lzw->parameters(), 'Predictor'));
        self::assertSame(0, $this->integerParameter($lzw->parameters(), 'EarlyChange'));
        self::assertFalse($flate->parameters()?->has('EarlyChange'));
        self::assertNull((new FlateDecode())->parameters());
    }

    public function testIncompleteRowsFailBeforeEncoding(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PngPredictor(new PredictorLayout(new IntegerType(3), new IntegerType(2))))->encode('abc');
    }

    public function testRowBitSizeOverflowFailsBeforeAnyAllocation(): void
    {
        $this->expectException(\OverflowException::class);
        new PredictorLayout(new IntegerType(PHP_INT_MAX), new IntegerType(1), PredictorBitsPerComponent::Sixteen);
    }

    public function testRowSampleCountOverflowFailsBeforeAnyAllocation(): void
    {
        $this->expectException(\OverflowException::class);
        new PredictorLayout(new IntegerType(PHP_INT_MAX), new IntegerType(2));
    }

    public function testEmptyRowsDoNotAllocateTheDeclaredMaximumRow(): void
    {
        $layout = new PredictorLayout(new IntegerType(1), new IntegerType(PHP_INT_MAX), PredictorBitsPerComponent::One);
        self::assertSame('', (new PngPredictor($layout))->encode(''));
        self::assertSame('', (new TiffPredictor($layout))->encode(''));
    }

    private function integerParameter(DictionaryType $parameters, string $key): int
    {
        $value = $parameters->get($key);
        self::assertInstanceOf(IntegerType::class, $value);
        return $value->integer();
    }
}
