<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** FunctionType 2: y[j] = C0[j] + x^N * (C1[j] - C0[j]) (7.10.3). */
final class ExponentialInterpolationFunction extends FunctionDictionary
{
    public readonly NumberArray $c0;
    public readonly NumberArray $c1;

    public function __construct(Domain $domain, public readonly NumericType $exponent, ?NumberArray $c0 = null, ?NumberArray $c1 = null, ?Range $range = null)
    {
        if ($domain->dimensions() !== 1) {
            throw new \InvalidArgumentException('An exponential interpolation function has exactly one input.');
        }
        $this->c0 = $c0 ?? new NumberArray(new NumericType(0));
        $this->c1 = $c1 ?? new NumberArray(new NumericType(1));
        if (count($this->c0) === 0 || count($this->c0) !== count($this->c1)) {
            throw new \InvalidArgumentException('C0 and C1 must have the same positive number of components.');
        }
        if ($range !== null && $range->dimensions() !== count($this->c0)) {
            throw new \InvalidArgumentException('The function range must have one interval per output component.');
        }
        $interval = $domain->intervals[0];
        $n = $exponent->value;
        if (floor($n) !== (float) $n && $interval->minimum->value < 0) {
            throw new \InvalidArgumentException('A fractional exponent requires a non-negative domain.');
        }
        if ($n < 0 && $interval->minimum->value <= 0 && $interval->maximum->value >= 0) {
            throw new \InvalidArgumentException('A negative exponent requires a domain that excludes zero.');
        }
        parent::__construct(
            new IntegerType(2),
            $domain,
            $range,
            new Entry(new NameType('C0'), $this->c0),
            new Entry(new NameType('C1'), $this->c1),
            new Entry(new NameType('N'), $exponent),
        );
    }

    public function outputCount(): int
    {
        return count($this->c0);
    }
}
