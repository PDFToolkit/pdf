<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;

/** Relations from 7.10; function evaluation remains in the native function objects. */
final readonly class FunctionDimensions implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $type = $values->value($dictionary, 'FunctionType');
        $domain = $values->numbers($dictionary, 'Domain');
        $range = $values->numbers($dictionary, 'Range');
        if (!$type instanceof IntegerType || $domain === null) {
            return;
        }
        $kind = $type->integer();
        if (in_array($kind, [0, 4], true) && $range === null) {
            throw new \InvalidArgumentException('/Range is required for sampled and calculator functions.');
        }
        if (in_array($kind, [2, 3], true) && count($domain) !== 2) {
            throw new \InvalidArgumentException('Exponential and stitching functions require one input.');
        }
        if ($kind === 0) {
            $size = $values->numbers($dictionary, 'Size');
            if ($size !== null && count($size) * 2 !== count($domain)) {
                throw new \InvalidArgumentException('/Size dimensionality must match /Domain.');
            }
            $this->sameLength($values->numbers($dictionary, 'Encode'), $domain, 'Encode', 'Domain');
            $this->sameLength($values->numbers($dictionary, 'Decode'), $range, 'Decode', 'Range');
        }
        if ($kind === 2) {
            $c0 = $values->numbers($dictionary, 'C0') ?? [0];
            $c1 = $values->numbers($dictionary, 'C1') ?? [1];
            if (count($c0) !== count($c1) || ($range !== null && count($range) !== count($c0) * 2)) {
                throw new \InvalidArgumentException('/C0, /C1 and /Range output dimensions disagree.');
            }
            $exponent = $values->value($dictionary, 'N');
            if ($exponent instanceof NumericType) {
                $n = $exponent->value;
                if (floor($n) !== (float) $n && $domain[0] < 0) {
                    throw new \InvalidArgumentException('A fractional /N requires a non-negative /Domain.');
                }
                if ($n < 0 && $domain[0] <= 0 && $domain[1] >= 0) {
                    throw new \InvalidArgumentException('A negative /N requires a /Domain that excludes zero.');
                }
            }
        }
        if ($kind === 3) {
            $functions = $values->value($dictionary, 'Functions');
            $bounds = $values->numbers($dictionary, 'Bounds');
            $encode = $values->numbers($dictionary, 'Encode');
            if ($functions instanceof ArrayType && (count($functions) < 1 || ($bounds !== null && count($bounds) !== count($functions) - 1) || ($encode !== null && count($encode) !== 2 * count($functions)))) {
                throw new \InvalidArgumentException('/Functions, /Bounds and /Encode stitching dimensions disagree.');
            }
            foreach ($bounds ?? [] as $bound) {
                if ($bound < $domain[0] || $bound > $domain[1]) {
                    throw new \InvalidArgumentException('/Bounds lies outside /Domain.');
                }
            }
        }
    }

    /**
     * @param list<int|float>|null $actual
     * @param list<int|float>|null $reference
     */
    private function sameLength(?array $actual, ?array $reference, string $key, string $referenceKey): void
    {
        if ($actual !== null && $reference !== null && count($actual) !== count($reference)) {
            throw new \InvalidArgumentException('/' . $key . ' length must match /' . $referenceKey . '.');
        }
    }
}
