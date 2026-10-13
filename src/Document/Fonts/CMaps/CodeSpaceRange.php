<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\StringObject\HexadecimalString;

/** ISO p.288: every source byte lies between its corresponding lower/upper byte. */
final readonly class CodeSpaceRange
{
    public function __construct(public CMapCode $low, public CMapCode $high)
    {
        if ($low->length() !== $high->length()) {
            throw new \InvalidArgumentException('Codespace bounds have the same byte width.');
        }
        for ($index = 0; $index < $low->length(); ++$index) {
            if (ord($low->value->value[$index]) > ord($high->value->value[$index])) {
                throw new \InvalidArgumentException('Each codespace lower byte is no larger than its matching upper byte.');
            }
        }
    }

    public function contains(CMapCode $code): bool
    {
        if ($code->length() !== $this->low->length()) {
            return false;
        }
        for ($index = 0; $index < $code->length(); ++$index) {
            $value = ord($code->value->value[$index]);
            if ($value < ord($this->low->value->value[$index]) || $value > ord($this->high->value->value[$index])) {
                return false;
            }
        }
        return true;
    }

    /** Equal-width intersections and shorter-code prefixes both cause ambiguous decoding. */
    public function conflicts(self $other): bool
    {
        $length = min($this->low->length(), $other->low->length());
        for ($index = 0; $index < $length; ++$index) {
            if (ord($this->low->value->value[$index]) > ord($other->high->value->value[$index]) || ord($other->low->value->value[$index]) > ord($this->high->value->value[$index])) {
                return false;
            }
        }
        return true;
    }

    /** Largest consecutive numeric interval contained in this bytewise box from the given code. */
    public function consecutiveEnd(CMapCode $start): CMapCode
    {
        if (!$this->contains($start)) {
            throw new \InvalidArgumentException('The consecutive run must start in its codespace.');
        }
        $bytes = $start->value->value;
        for ($index = strlen($bytes) - 1; $index >= 0; --$index) {
            $bytes[$index] = $this->high->value->value[$index];
            if (ord($this->low->value->value[$index]) !== 0 || ord($this->high->value->value[$index]) !== 255) {
                break;
            }
        }
        return new CMapCode(new HexadecimalString($bytes));
    }
}
