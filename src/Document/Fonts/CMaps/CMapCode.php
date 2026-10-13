<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** One source character code of 1–4 bytes (ISO 9.7.6.2, PDF p.288). */
final readonly class CMapCode
{
    public function __construct(public StringType $value)
    {
        if (strlen($value->value) < 1 || strlen($value->value) > 4) {
            throw new \InvalidArgumentException('A CMap source character code contains 1–4 bytes.');
        }
    }

    public function length(): int
    {
        return strlen($this->value->value);
    }

    public function hex(): string
    {
        return '<' . strtoupper(bin2hex($this->value->value)) . '>';
    }

    /** Exact for all unsigned 32-bit codes, including on a 32-bit PHP runtime. */
    public function number(): float
    {
        $number = 0.0;
        foreach (str_split($this->value->value) as $byte) {
            $number = $number * 256 + ord($byte);
        }
        return $number;
    }

    public function add(int $increment): self
    {
        if ($increment < 0) {
            throw new \InvalidArgumentException('A code increment is nonnegative.');
        }
        $bytes = $this->value->value;
        for ($index = strlen($bytes) - 1; $index >= 0; --$index) {
            $sum = ord($bytes[$index]) + $increment % 256;
            $bytes[$index] = chr($sum % 256);
            $increment = intdiv($increment, 256) + intdiv($sum, 256);
        }
        if ($increment !== 0) {
            throw new \OverflowException('A code increment exceeds its declared byte width.');
        }
        return new self(new HexadecimalString($bytes));
    }
}
