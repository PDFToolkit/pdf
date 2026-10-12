<?php

declare(strict_types=1);

namespace PDF\Syntax\Object;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Serialization\SerializationContext;
use PDF\Serialization\SerializesPdf;

class NumericType implements BasicType
{
    use SerializesPdf;
    public function __construct(public readonly int|float $value)
    {
        if (is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('PDF numbers must be finite.');
        }
    }

    public function toPdf(SerializationContext $context): string
    {
        if (is_int($this->value)) {
            return (string) $this->value;
        }
        if ($this->value == 0.0) {
            return '0';
        }
        // Locale independent, shortest round-trip representation; PDF forbids exponent notation.
        $decimal = json_encode($this->value, JSON_THROW_ON_ERROR);
        if (!str_contains(strtolower($decimal), 'e')) {
            return $decimal;
        }
        [$mantissa, $exponent] = explode('e', strtolower($decimal));
        $negative = str_starts_with($mantissa, '-');
        $mantissa = ltrim($mantissa, '-');
        $point = strpos($mantissa, '.');
        $digits = str_replace('.', '', $mantissa);
        $position = ($point === false ? strlen($mantissa) : $point) + (int) $exponent;
        $result = $position <= 0 ? '0.' . str_repeat('0', -$position) . $digits
            : ($position >= strlen($digits) ? $digits . str_repeat('0', $position - strlen($digits))
                : substr($digits, 0, $position) . '.' . substr($digits, $position));
        if (str_contains($result, '.')) {
            $result = rtrim(rtrim($result, '0'), '.');
        }
        return ($negative ? '-' : '') . $result;
    }
}
