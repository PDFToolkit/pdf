<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/** FunctionType 0 stream with a validated multidimensional sample grid. */
final class SampledFunction extends StreamObject implements FunctionObject
{
    public readonly NumberArray $encode;
    public readonly NumberArray $decode;

    public function __construct(
        private readonly Domain $inputDomain,
        private readonly Range $outputRange,
        public readonly SamplingSize $size,
        public readonly SampleBitDepth $bitsPerSample,
        public readonly SampleValues $samples,
        public readonly InterpolationOrder $order = InterpolationOrder::Linear,
        ?NumberArray $encode = null,
        ?NumberArray $decode = null,
    ) {
        if ($size->dimensions() !== $inputDomain->dimensions()) {
            throw new \InvalidArgumentException('Sampling Size needs one element per input dimension.');
        }
        if ($size->sampleCount() > intdiv(PHP_INT_MAX, $outputRange->dimensions())) {
            throw new \InvalidArgumentException('The total sample-component count exceeds the platform integer range.');
        }
        if (count($samples) !== $size->sampleCount() * $outputRange->dimensions()) {
            throw new \InvalidArgumentException('The number of sample values must equal product(Size) times the output count.');
        }
        $defaultEncode = [];
        foreach ($size->sizes as $dimensionSize) {
            $defaultEncode[] = new NumericType(0);
            $defaultEncode[] = new NumericType($dimensionSize->value - 1);
        }
        $defaultDecode = [];
        foreach ($outputRange->intervals as $interval) {
            $defaultDecode[] = $interval->minimum;
            $defaultDecode[] = $interval->maximum;
        }
        $this->encode = $encode ?? new NumberArray(...$defaultEncode);
        $this->decode = $decode ?? new NumberArray(...$defaultDecode);
        if (count($this->encode) !== 2 * $inputDomain->dimensions()) {
            throw new \InvalidArgumentException('Sampled Encode needs one pair per input dimension.');
        }
        if (count($this->decode) !== 2 * $outputRange->dimensions()) {
            throw new \InvalidArgumentException('Sampled Decode needs one pair per output dimension.');
        }
        parent::__construct($samples->pack($bitsPerSample), new DictionaryType(
            new Entry(new NameType('FunctionType'), new IntegerType(0)),
            new Entry(new NameType('Domain'), $inputDomain),
            new Entry(new NameType('Range'), $outputRange),
            new Entry(new NameType('Size'), $size),
            new Entry(new NameType('BitsPerSample'), new IntegerType($bitsPerSample->value)),
            new Entry(new NameType('Order'), new IntegerType($order->value)),
            new Entry(new NameType('Encode'), $this->encode),
            new Entry(new NameType('Decode'), $this->decode),
        ));
    }

    public function domain(): Domain
    {
        return $this->inputDomain;
    }

    public function range(): Range
    {
        return $this->outputRange;
    }

    public function inputCount(): int
    {
        return $this->inputDomain->dimensions();
    }

    public function outputCount(): int
    {
        return $this->outputRange->dimensions();
    }
}
