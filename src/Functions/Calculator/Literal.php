<?php

declare(strict_types=1);

namespace PDF\Functions\Calculator;

/** Immutable emitted numeric token: whole-valued real syntax remains real. */
final readonly class Literal implements Instruction
{
    public int|float $value;

    public function __construct(private string $source)
    {
        if (preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $source) !== 1) {
            throw new \InvalidArgumentException('A calculator number must emit exactly one decimal numeric token.');
        }
        // Decimal PostScript tokens may have leading zeros; FILTER_VALIDATE_INT
        // rejects those spellings unless their value is canonicalized first.
        $digits = ltrim(ltrim($source, '+-'), '0');
        $canonical = $digits === '' ? '0' : (str_starts_with($source, '-') ? '-' : '') . $digits;
        $integer = str_contains($source, '.') ? false : filter_var($canonical, FILTER_VALIDATE_INT);
        $value = $integer === false ? (float) $source : $integer;
        if (!is_finite((float) $value)) {
            throw new \InvalidArgumentException('Calculator literals must fit the explicit finite numeric implementation.');
        }
        $this->value = $value;
    }

    public function toPostScript(): string
    {
        return $this->source;
    }
}
