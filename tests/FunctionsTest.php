<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Functions\Calculator\Number;
use PDF\Functions\Calculator\Operator;
use PDF\Functions\Calculator\Procedure;
use PDF\Functions\Domain;
use PDF\Functions\ExponentialInterpolationFunction;
use PDF\Functions\NumberArray;
use PDF\Functions\NumberInterval;
use PDF\Functions\InterpolationOrder;
use PDF\Functions\SampleBitDepth;
use PDF\Functions\SampledFunction;
use PDF\Functions\SampleValues;
use PDF\Functions\SamplingSize;
use PDF\Functions\PostScriptCalculatorFunction;
use PDF\Functions\Range;
use PDF\Functions\StitchingFunction;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FunctionsTest extends TestCase
{
    private function domain(): Domain
    {
        return new Domain(new NumberInterval(new NumericType(0), new NumericType(1)));
    }

    private function range(): Range
    {
        return new Range(new NumberInterval(new NumericType(0), new NumericType(1)));
    }

    public function testExponentialDefaultsProduceOneOutputAndExactPdfEntries(): void
    {
        $function = new ExponentialInterpolationFunction($this->domain(), new NumericType(2));
        self::assertSame(1, $function->inputCount());
        self::assertSame(1, $function->outputCount());
        self::assertNull($function->range());
        self::assertSame('<< /FunctionType 2 /Domain [0 1] /C0 [0] /C1 [1] /N 2 >>', (string) $function);
    }

    public function testExponentialSupportsMultipleComponentsAndClippingRange(): void
    {
        $range = new Range(
            new NumberInterval(new NumericType(0), new NumericType(1)),
            new NumberInterval(new NumericType(0), new NumericType(1)),
        );
        $function = new ExponentialInterpolationFunction(
            $this->domain(),
            new NumericType(1),
            new NumberArray(new NumericType(0), new NumericType(1)),
            new NumberArray(new NumericType(1), new NumericType(0)),
            $range,
        );
        self::assertSame(2, $function->outputCount());
        self::assertSame($range, $function->range());
        self::assertStringContainsString('/Range [0 1 0 1]', (string) $function);
    }

    public function testFractionalExponentRejectsNegativeDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExponentialInterpolationFunction(new Domain(new NumberInterval(new NumericType(-1), new NumericType(1))), new NumericType(0.5));
    }

    public function testNegativeExponentRejectsDomainContainingZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExponentialInterpolationFunction($this->domain(), new NumericType(-1));
    }

    public function testExponentialRejectsMismatchedOutputComponentCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExponentialInterpolationFunction($this->domain(), new NumericType(1), new NumberArray(new NumericType(0), new NumericType(0)), new NumberArray(new NumericType(1)));
    }

    public function testStitchingReferencesCompatibleFunctionsAndComputesOutputCount(): void
    {
        $first = new ExponentialInterpolationFunction($this->domain(), new NumericType(1));
        $second = new ExponentialInterpolationFunction($this->domain(), new NumericType(2));
        $function = new StitchingFunction(
            $this->domain(),
            new NumberArray(new NumericType(0.5)),
            new NumberArray(new NumericType(0), new NumericType(1), new NumericType(0), new NumericType(1)),
            $this->range(),
            $first,
            new IndirectReference($second),
        );
        self::assertSame(1, $function->inputCount());
        self::assertSame(1, $function->outputCount());
        self::assertSame($first, $function->get('Functions')->items[0]->target());
        self::assertSame($second, $function->get('Functions')->items[1]->target());
        $context = new SerializationContext();
        $context->prepare(new IndirectReference($function));
        self::assertStringContainsString('/Bounds [0.5]', $context->serialize($function));
    }

    public function testStitchingRejectsBoundOutsideDomain(): void
    {
        $f = new ExponentialInterpolationFunction($this->domain(), new NumericType(1));
        $this->expectException(\InvalidArgumentException::class);
        new StitchingFunction($this->domain(), new NumberArray(new NumericType(1.1)), new NumberArray(new NumericType(0), new NumericType(1), new NumericType(0), new NumericType(1)), null, $f, $f);
    }

    public function testStitchingRejectsNonFunctionReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StitchingFunction($this->domain(), new NumberArray(), new NumberArray(new NumericType(0), new NumericType(1)), null, new IndirectReference(new DictionaryType()));
    }

    public function testCalculatorGrammarSupportsNestedProcedures(): void
    {
        $procedure = new Procedure(
            Operator::Duplicate,
            new Number(new NumericType(0.5)),
            Operator::GreaterThan,
            new Procedure(new Number(new NumericType(1)), Operator::Exchange, Operator::Subtract),
            new Procedure(Operator::Duplicate, Operator::Multiply),
            Operator::IfElse,
        );
        self::assertSame('{ dup 0.5 gt { 1 exch sub } { dup mul } ifelse }', $procedure->toPostScript());
        $function = new PostScriptCalculatorFunction($this->domain(), $this->range(), $procedure);
        self::assertSame(1, $function->inputCount());
        self::assertSame(1, $function->outputCount());
        $context = new SerializationContext();
        $context->prepare(new IndirectReference($function));
        $pdf = $function->toPdf($context);
        self::assertStringContainsString('/FunctionType 4', $pdf);
        self::assertStringContainsString('/Range [0 1]', $pdf);
        self::assertStringContainsString("stream\n{ dup 0.5 gt", $pdf);
    }

    public function testFunctionRequiredEntriesCannotBeRemoved(): void
    {
        $function = new ExponentialInterpolationFunction($this->domain(), new NumericType(1));
        $this->expectException(\LogicException::class);
        $function->without('Domain');
    }

    public function testStitchingAllowsLastBoundEqualToDomainMaximum(): void
    {
        $f = new ExponentialInterpolationFunction($this->domain(), new NumericType(1));
        $function = new StitchingFunction($this->domain(), new NumberArray(new NumericType(1)), new NumberArray(new NumericType(0), new NumericType(1), new NumericType(0), new NumericType(1)), null, $f, $f);
        self::assertSame(1, $function->bounds->numbers[0]->value);
    }

    public function testStitchingAllowsSingleFunctionWithDegenerateDomain(): void
    {
        $f = new ExponentialInterpolationFunction($this->domain(), new NumericType(1));
        $domain = new Domain(new NumberInterval(new NumericType(1), new NumericType(1)));
        $function = new StitchingFunction($domain, new NumberArray(), new NumberArray(new NumericType(0), new NumericType(1)), null, $f);
        self::assertSame($domain, $function->domain());
    }

    /** @return iterable<string, array{SampleBitDepth, list<int>, string}> */
    public static function packedSamples(): iterable
    {
        yield '1 bit with padding' => [SampleBitDepth::One, [1, 0, 1], 'a0'];
        yield '2 bits with padding' => [SampleBitDepth::Two, [0, 1, 2], '18'];
        yield '4 bits with padding' => [SampleBitDepth::Four, [10, 11, 12], 'abc0'];
        yield '8 bits' => [SampleBitDepth::Eight, [0, 255], '00ff'];
        yield '12 bits continuous with padding' => [SampleBitDepth::Twelve, [0xabc, 0x123, 0xfff], 'abc123fff0'];
        yield '16 bits big endian' => [SampleBitDepth::Sixteen, [1, 65535], '0001ffff'];
        yield '24 bits big endian' => [SampleBitDepth::TwentyFour, [0x123456, 0xffffff], '123456ffffff'];
        yield '32 bits unsigned' => [SampleBitDepth::ThirtyTwo, [0x89abcdef, 4_294_967_295], '89abcdefffffffff'];
    }

    /** @param list<int> $values */
    #[DataProvider('packedSamples')]
    public function testSamplePackingIsMsbFirstAndZeroPadsFinalByte(SampleBitDepth $depth, array $values, string $hex): void
    {
        $samples = new SampleValues(...array_map(static fn (int $value): IntegerType => new IntegerType($value), $values));
        self::assertSame($hex, bin2hex($samples->pack($depth)));
    }

    public function testSampledFunctionSupportsTwoOutputsAnd16BitData(): void
    {
        $range = new Range(new NumberInterval(new NumericType(0), new NumericType(1)), new NumberInterval(new NumericType(-1), new NumericType(1)));
        $function = new SampledFunction(
            $this->domain(),
            $range,
            new SamplingSize(new IntegerType(2)),
            SampleBitDepth::Sixteen,
            new SampleValues(new IntegerType(1), new IntegerType(2), new IntegerType(65535), new IntegerType(0)),
            InterpolationOrder::Cubic,
        );
        self::assertSame(1, $function->inputCount());
        self::assertSame(2, $function->outputCount());
        self::assertSame('00010002ffff0000', bin2hex($function->data));
        self::assertSame('[0 1]', (string) $function->encode);
        self::assertSame('[0 1 -1 1]', (string) $function->decode);
        self::assertSame(3, $function->dictionary()->get('Order')->value);
    }

    public function testSampledFunctionSupportsMultidimensionalGrid(): void
    {
        $domain = new Domain(new NumberInterval(new NumericType(0), new NumericType(1)), new NumberInterval(new NumericType(0), new NumericType(1)));
        $function = new SampledFunction(
            $domain,
            $this->range(),
            new SamplingSize(new IntegerType(2), new IntegerType(3)),
            SampleBitDepth::Eight,
            new SampleValues(new IntegerType(0), new IntegerType(1), new IntegerType(2), new IntegerType(3), new IntegerType(4), new IntegerType(5)),
        );
        self::assertSame(2, $function->inputCount());
        self::assertSame('[0 1 0 2]', (string) $function->encode);
        self::assertSame('000102030405', bin2hex($function->data));
    }

    public function testSampledFunctionSupportsTwoUnsigned32BitOutputs(): void
    {
        $range = new Range(new NumberInterval(new NumericType(0), new NumericType(1)), new NumberInterval(new NumericType(0), new NumericType(1)));
        $function = new SampledFunction($this->domain(), $range, new SamplingSize(new IntegerType(1)), SampleBitDepth::ThirtyTwo, new SampleValues(new IntegerType(0x89abcdef), new IntegerType(4_294_967_295)));
        self::assertSame(2, $function->outputCount());
        self::assertSame('89abcdefffffffff', bin2hex($function->data));
    }

    public function testSampledFunctionRejectsWrongSampleCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SampledFunction($this->domain(), $this->range(), new SamplingSize(new IntegerType(2)), SampleBitDepth::Eight, new SampleValues(new IntegerType(0)));
    }

    public function testSampledFunctionRejectsEncodeWithWrongShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SampledFunction($this->domain(), $this->range(), new SamplingSize(new IntegerType(1)), SampleBitDepth::Eight, new SampleValues(new IntegerType(0)), encode: new NumberArray(new NumericType(0)));
    }

    public function testSampledFunctionRejectsDecodeWithWrongShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SampledFunction($this->domain(), $this->range(), new SamplingSize(new IntegerType(1)), SampleBitDepth::Eight, new SampleValues(new IntegerType(0)), decode: new NumberArray(new NumericType(0)));
    }

    public function testSamplingSizeRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SamplingSize(new IntegerType(0));
    }

    public function testSamplingSizeRejectsOverflowingGridProduct(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SamplingSize(new IntegerType(PHP_INT_MAX), new IntegerType(2));
    }

    public function testSamplePackingRejectsValueExceedingBitDepth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SampleValues(new IntegerType(4)))->pack(SampleBitDepth::Two);
    }

    public function testSampledFunctionStreamContainsPackedDataAndRequiredDictionary(): void
    {
        $function = new SampledFunction($this->domain(), $this->range(), new SamplingSize(new IntegerType(3)), SampleBitDepth::One, new SampleValues(new IntegerType(1), new IntegerType(0), new IntegerType(1)));
        $context = new SerializationContext();
        $context->prepare(new IndirectReference($function));
        $pdf = $function->toPdf($context);
        self::assertStringContainsString('/FunctionType 0', $pdf);
        self::assertStringContainsString('/Length 1', $pdf);
        self::assertStringContainsString("\nstream\n\xa0\nendstream", $pdf);
    }
}
