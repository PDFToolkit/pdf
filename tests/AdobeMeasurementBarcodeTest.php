<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Barcodes\BarcodeDataPreparation;
use PDF\Document\Barcodes\BarcodeModuleWidth;
use PDF\Document\Measurements\ThreeDUnitMapping;
use PDF\Document\Measurements\UnitMappingInterpretation;
use PDF\Serialization\SerializationContext;
use PDF\Specification\Adobe\Dictionary\ThreeDUnitsDictionaryTable9_33a;
use PDF\Specification\Adobe\Dictionary\ThreeDUnitsDictionaryTable9_33a\TSmEntry;
use PDF\Specification\Adobe\Dictionary\ThreeDUnitsDictionaryTable9_33a\TSnEntry;
use PDF\Specification\Adobe\Dictionary\ThreeDUnitsDictionaryTable9_33a\TUEntry;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

final class AdobeMeasurementBarcodeTest extends TestCase
{
    public function testDefaultUnitMappingHasTheSourceDefaultsAndRequiresAnExplicitInterpretation(): void
    {
        $constructor = new \ReflectionMethod(ThreeDUnitMapping::class, '__construct');
        self::assertFalse($constructor->getParameters()[1]->isDefaultValueAvailable());
        foreach ([UnitMappingInterpretation::PrintedEquation, UnitMappingInterpretation::UnitDescription] as $interpretation) {
            $mapping = new ThreeDUnitMapping(new ThreeDUnitsDictionaryTable9_33a(), $interpretation);
            $distance = $mapping->map(new NumericType(7.5));
            self::assertEquals(7.5, $distance->value->value);
            self::assertSame('Model Units', $distance->units->value);
            self::assertSame($mapping->units, $distance->units);
            self::assertSame(1, $mapping->m->value);
            self::assertSame(1, $mapping->n->value);
        }
    }

    public function testPrintedEquationAndUnitDescriptionRemainDistinctRatherThanSilentlyCorrected(): void
    {
        $m = new NumericType(2);
        $n = new NumericType(5);
        $label = new LiteralString('mm');
        $dictionary = new ThreeDUnitsDictionaryTable9_33a(new TSmEntry($m), new TSnEntry($n), new TUEntry($label));
        $equation = new ThreeDUnitMapping($dictionary, UnitMappingInterpretation::PrintedEquation);
        $description = new ThreeDUnitMapping($dictionary, UnitMappingInterpretation::UnitDescription);
        self::assertEquals(4, $equation->map(new NumericType(10))->value->value);
        self::assertEquals(25, $description->map(new NumericType(10))->value->value);
        self::assertSame($m, $equation->m);
        self::assertSame($n, $equation->n);
        self::assertSame($label, $equation->units);
        self::assertEquals(10, $description->map($equation->map(new NumericType(10))->value)->value->value);
    }

    public function testCreationUserAndDisplayStagesApplyThePrintedOrder(): void
    {
        $dictionary = $this->units([
            'TSm' => new NumericType(2), 'TSn' => new NumericType(5), 'TU' => new LiteralString('mm'),
            'USm' => new NumericType(3), 'UU' => new LiteralString('cm'),
            'DSm' => new NumericType(4), 'DSn' => new NumericType(2), 'DU' => new LiteralString('display'),
        ]);
        $mapping = new ThreeDUnitMapping($dictionary, UnitMappingInterpretation::PrintedEquation);
        // USm replaces TSm; omitted USn retains TSn. Display factors then multiply both.
        self::assertSame(12, $mapping->m->value);
        self::assertSame(10, $mapping->n->value);
        self::assertEquals(6, $mapping->map(new NumericType(5))->value->value);
        self::assertSame('display', $mapping->units->value);
        $inverse = new ThreeDUnitMapping($dictionary, UnitMappingInterpretation::UnitDescription);
        self::assertEquals(5, $inverse->map($mapping->map(new NumericType(5))->value)->value->value);
    }

    public function testALabelOverrideDoesNotResetUndefinedScaleFactors(): void
    {
        $dictionary = $this->units([
            'TSm' => new NumericType(2), 'TSn' => new NumericType(5), 'TU' => new LiteralString('creation'),
            'UU' => new LiteralString('override'), 'DU' => new LiteralString('display'),
        ]);
        $mapping = new ThreeDUnitMapping($dictionary, UnitMappingInterpretation::PrintedEquation);
        self::assertSame(2, $mapping->m->value);
        self::assertSame(5, $mapping->n->value);
        self::assertSame('display', $mapping->units->value);
    }

    public function testEachScaleRequiresItsCorrespondingLabel(): void
    {
        foreach (['TSm' => 'TU', 'TSn' => 'TU', 'USm' => 'UU', 'USn' => 'UU', 'DSm' => 'DU', 'DSn' => 'DU'] as $key => $label) {
            try {
                new ThreeDUnitMapping($this->units([$key => new NumericType(2)]), UnitMappingInterpretation::PrintedEquation);
                self::fail('A scale without its corresponding unit label was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('/' . $label . ' is required', $error->getMessage());
            }
        }
    }

    public function testUnitValuesCanBeReferencedExplicitlyWithoutReadingAnExternalPdf(): void
    {
        $label = new LiteralString('referenced label');
        $dictionary = $this->units([
            'TSm' => new IndirectReference(new NumericType(2)),
            'TSn' => new IndirectReference(new NumericType(5)),
            'TU' => new IndirectReference($label),
        ]);
        $mapping = new ThreeDUnitMapping($dictionary, UnitMappingInterpretation::PrintedEquation);
        self::assertEquals(4, $mapping->map(new NumericType(10))->value->value);
        self::assertSame($label, $mapping->units);
    }

    public function testNullValuedOptionalEntriesAreAbsent(): void
    {
        $mapping = new ThreeDUnitMapping($this->units(['TSm' => new NullType(), 'TU' => new NullType()]), UnitMappingInterpretation::PrintedEquation);
        self::assertSame('Model Units', $mapping->units->value);
        self::assertSame(1, $mapping->m->value);
        $this->expectException(\InvalidArgumentException::class);
        new ThreeDUnitMapping($this->units(['TSm' => new NumericType(2), 'TU' => new NullType()]), UnitMappingInterpretation::PrintedEquation);
    }

    public function testUnitDictionaryTypesAreCheckedBeforeNumericProcessing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ThreeDUnitMapping($this->units(['TSm' => new LiteralString('2'), 'TU' => new LiteralString('mm')]), UnitMappingInterpretation::PrintedEquation);
    }

    public function testZeroIsRejectedOnlyWhereTheChosenFormulaDividesByIt(): void
    {
        $zeroM = $this->units(['TSm' => new NumericType(0), 'TSn' => new NumericType(1), 'TU' => new LiteralString('unit')]);
        self::assertSame(0, (new ThreeDUnitMapping($zeroM, UnitMappingInterpretation::PrintedEquation))->map(new NumericType(10))->value->value);
        try {
            $result = (new ThreeDUnitMapping($zeroM, UnitMappingInterpretation::UnitDescription))->map(new NumericType(10));
            self::fail('The prose interpretation divided by zero.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('zero denominator', $error->getMessage());
        }
        $zeroN = $this->units(['TSm' => new NumericType(1), 'TSn' => new NumericType(0), 'TU' => new LiteralString('unit')]);
        self::assertSame(0, (new ThreeDUnitMapping($zeroN, UnitMappingInterpretation::UnitDescription))->map(new NumericType(10))->value->value);
        $this->expectException(\InvalidArgumentException::class);
        $result = (new ThreeDUnitMapping($zeroN, UnitMappingInterpretation::PrintedEquation))->map(new NumericType(10));
    }

    public function testZeroModelDistanceRemainsZeroEvenWithAVeryLargeFiniteRatio(): void
    {
        $mapping = new ThreeDUnitMapping($this->units(['TSm' => new NumericType(1.0e308), 'TSn' => new NumericType(1.0e-308), 'TU' => new LiteralString('unit')]), UnitMappingInterpretation::PrintedEquation);
        self::assertSame(0, $mapping->map(new NumericType(0))->value->value);
        $this->expectException(\OutOfRangeException::class);
        $result = $mapping->map(new NumericType(1));
    }

    public function testUnitMappingRejectsOverflowAndUnderflowInsteadOfInventingZeroOrInfinity(): void
    {
        foreach ([[1.0e308, 1.0e-308], [1.0e-308, 1.0e308]] as [$m, $n]) {
            $mapping = new ThreeDUnitMapping($this->units(['TSm' => new NumericType($m), 'TSn' => new NumericType($n), 'TU' => new LiteralString('unit')]), UnitMappingInterpretation::PrintedEquation);
            try {
                $result = $mapping->map(new NumericType(1));
                self::fail('An unrepresentable scale ratio was accepted.');
            } catch (\OutOfRangeException $error) {
                self::assertStringContainsString('numeric range', $error->getMessage());
            }
        }
        foreach ([1.0e308, 1.0e-308] as $factor) {
            try {
                new ThreeDUnitMapping($this->units(['TSm' => new NumericType($factor), 'TU' => new LiteralString('creation'), 'DSm' => new NumericType($factor), 'DU' => new LiteralString('display')]), UnitMappingInterpretation::PrintedEquation);
                self::fail('An unrepresentable display scale product was accepted.');
            } catch (\OutOfRangeException $error) {
                self::assertStringContainsString('scale product', $error->getMessage());
            }
        }
    }

    public function testPdfNumericObjectsRejectNonFiniteOperands(): void
    {
        foreach ([INF, -INF, NAN] as $value) {
            try {
                new NumericType($value);
                self::fail('A non-finite operand was accepted as a PDF number.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('finite', $error->getMessage());
            }
        }
    }

    public function testBarcodeWidthUsesTheSourceRoundingAndDefaultResolution(): void
    {
        $oneInch = new BarcodeModuleWidth(new IntegerType(1));
        self::assertSame(300, $oneInch->XSymWidth->integer());
        self::assertSame(300, $oneInch->resolution->value);
        self::assertSame($oneInch->XSymWidth, $oneInch->entry()->value());
        self::assertSame('XSymWidth', $oneInch->entry()->key()->value);
        self::assertSame(4, (new BarcodeModuleWidth(new NumericType(0.01), new IntegerType(301)))->XSymWidth->integer());
        self::assertSame(1, (new BarcodeModuleWidth(new NumericType(0.001), new IntegerType(300)))->XSymWidth->integer());
    }

    public function testBarcodeWidthRejectsNonPositiveGeometryAndResolution(): void
    {
        foreach ([[0, 300], [-1, 300], [1, 0], [1, -300]] as [$distance, $resolution]) {
            try {
                new BarcodeModuleWidth(new NumericType($distance), new NumericType($resolution));
                self::fail('Non-positive barcode geometry was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('positive', $error->getMessage());
            }
        }
    }

    public function testBarcodeWidthChecksNativeIntegerBoundariesBeforeConversion(): void
    {
        self::assertSame(PHP_INT_MAX, (new BarcodeModuleWidth(new IntegerType(PHP_INT_MAX), new IntegerType(1)))->XSymWidth->integer());
        foreach ([[new IntegerType(PHP_INT_MAX), new IntegerType(2)], [new NumericType(1.0e308), new NumericType(1.0e308)], [new NumericType(1.0e-300), new NumericType(1.0e-300)]] as [$distance, $resolution]) {
            try {
                new BarcodeModuleWidth($distance, $resolution);
                self::fail('An unrepresentable barcode module width was accepted.');
            } catch (\OutOfRangeException) {
                self::assertTrue(true);
            }
        }
        if (PHP_INT_SIZE === 8) {
            $this->expectException(\OutOfRangeException::class);
            new BarcodeModuleWidth(new NumericType((float) PHP_INT_MAX), new IntegerType(1));
        }
    }

    public function testUncompressedBarcodePreparationKeepsTheExplicitByteObject(): void
    {
        $bytes = new LiteralString("\0(data)\\\r\n\xff");
        $preparation = new BarcodeDataPreparation();
        self::assertSame($bytes, $preparation->prepare($bytes));
        self::assertSame(0, $preparation->entry()->value()->value);
        self::assertSame('<< /DataPrep 0 >>', (new DictionaryType($preparation->entry()))->toPdf(new SerializationContext()));
    }

    public function testFlateBarcodePreparationCompressesPayloadBytesRatherThanPdfSyntax(): void
    {
        $bytes = '';
        for ($value = 0; $value <= 255; $value++) {
            $bytes .= chr($value);
        }
        $input = new LiteralString($bytes . str_repeat('(payload)\\', 100));
        $dataPrep = new NumericType(1.0);
        $preparation = new BarcodeDataPreparation($dataPrep, new FlateDecode(9));
        $prepared = $preparation->prepare($input);
        self::assertInstanceOf(HexadecimalString::class, $prepared);
        self::assertNotSame($input, $prepared);
        self::assertSame($input->value, gzuncompress($prepared->value));
        self::assertNotSame($input->toPdf(new SerializationContext()), gzuncompress($prepared->value));
        self::assertSame($dataPrep, $preparation->entry()->value());
        self::assertSame("\x78", $prepared->value[0], 'Flate is zlib framed rather than raw deflate or gzip.');
        self::assertSame('', gzuncompress($preparation->prepare(new HexadecimalString(''))->value));
    }

    public function testBarcodeDataPreparationRejectsUndefinedPreparationValues(): void
    {
        foreach ([-1, 2, 0.5, 1.5] as $value) {
            try {
                new BarcodeDataPreparation(new NumericType($value));
                self::fail('An undefined DataPrep value was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('0 or 1', $error->getMessage());
            }
        }
    }

    /** @param array<string, BasicType> $values */
    private function units(array $values): DictionaryType
    {
        $entries = [];
        foreach ($values as $key => $value) {
            $entries[] = new Entry(new NameType($key), $value);
        }
        return new DictionaryType(...$entries);
    }
}
