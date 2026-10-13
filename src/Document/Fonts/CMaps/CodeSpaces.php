<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\StringObject\HexadecimalString;

final readonly class CodeSpaces
{
    /** @var list<CodeSpaceRange> */
    public array $ranges;

    public function __construct(CodeSpaceRange ...$ranges)
    {
        $unique = [];
        foreach ($ranges as $range) {
            $identity = $range->low->hex() . $range->high->hex();
            if (isset($unique[$identity])) {
                continue;
            }
            foreach ($unique as $existing) {
                if ($range->conflicts($existing)) {
                    throw new \InvalidArgumentException('Codespace ranges cannot overlap or have ambiguous shorter prefixes.');
                }
            }
            $unique[$identity] = $range;
        }
        $this->ranges = array_values($unique);
    }

    public function contains(CMapCode $code): bool
    {
        foreach ($this->ranges as $range) {
            if ($range->contains($code)) {
                return true;
            }
        }
        return false;
    }

    /** Validate bounded mapping intervals without enumerating every character. */
    public function covers(CMapCodeRange $interval): bool
    {
        foreach ($this->numericBoxes($interval->low->value->value, $interval->high->value->value) as $box) {
            if (!$this->coversSpace($box)) {
                return false;
            }
        }
        return true;
    }

    public function coversSpace(CodeSpaceRange $box): bool
    {
        return $this->coversBox($box, $this->ranges);
    }

    /** @param list<CodeSpaceRange> $candidates */
    private function coversBox(CodeSpaceRange $box, array $candidates): bool
    {
        $overlapping = [];
        foreach ($candidates as $candidate) {
            if ($candidate->low->length() !== $box->low->length() || !$candidate->conflicts($box)) {
                continue;
            }
            if ($candidate->contains($box->low) && $candidate->contains($box->high)) {
                return true;
            }
            $overlapping[] = $candidate;
        }
        foreach ($overlapping as $candidate) {
            for ($index = 0; $index < $box->low->length(); ++$index) {
                $low = ord($box->low->value->value[$index]);
                $high = ord($box->high->value->value[$index]);
                foreach ([ord($candidate->low->value->value[$index]), ord($candidate->high->value->value[$index]) + 1] as $cut) {
                    if ($cut > $low && $cut <= $high) {
                        $lowerHigh = $box->high->value->value;
                        $lowerHigh[$index] = chr($cut - 1);
                        $upperLow = $box->low->value->value;
                        $upperLow[$index] = chr($cut);
                        return $this->coversBox(new CodeSpaceRange($box->low, new CMapCode(new HexadecimalString($lowerHigh))), $overlapping)
                            && $this->coversBox(new CodeSpaceRange(new CMapCode(new HexadecimalString($upperLow)), $box->high), $overlapping);
                    }
                }
            }
        }
        return false;
    }

    /**
     * Turn a numeric interval into a few bytewise boxes, never enumerate 2^32 source codes.
     * @return list<CodeSpaceRange>
     */
    private function numericBoxes(string $low, string $high): array
    {
        for ($index = 0; $index < strlen($low); ++$index) {
            if ($low[$index] === $high[$index]) {
                continue;
            }
            $tail = strlen($low) - $index - 1;
            if (substr($low, $index + 1) === str_repeat("\0", $tail) && substr($high, $index + 1) === str_repeat("\xff", $tail)) {
                break;
            }
            $lowerHigh = substr($low, 0, $index + 1) . str_repeat("\xff", $tail);
            $upperLow = substr($high, 0, $index + 1) . str_repeat("\0", $tail);
            $boxes = $this->numericBoxes($low, $lowerHigh);
            if (ord($high[$index]) - ord($low[$index]) > 1) {
                $middleLow = substr($low, 0, $index) . chr(ord($low[$index]) + 1) . str_repeat("\0", $tail);
                $middleHigh = substr($high, 0, $index) . chr(ord($high[$index]) - 1) . str_repeat("\xff", $tail);
                array_push($boxes, ...$this->numericBoxes($middleLow, $middleHigh));
            }
            array_push($boxes, ...$this->numericBoxes($upperLow, $high));
            return $boxes;
        }
        return [new CodeSpaceRange(new CMapCode(new HexadecimalString($low)), new CMapCode(new HexadecimalString($high)))];
    }

    /** Explicit decoding of supplied character-code bytes, not UTF-8/font program conversion. */
    public function decode(HexadecimalString $string): CMapCodeSequence
    {
        $bytes = $string->value;
        $codes = [];
        for ($offset = 0; $offset < strlen($bytes);) {
            $matched = null;
            for ($length = 1; $length <= min(4, strlen($bytes) - $offset); ++$length) {
                $code = new CMapCode(new HexadecimalString(substr($bytes, $offset, $length)));
                if ($this->contains($code)) {
                    $matched = $code;
                    break;
                }
            }
            if ($matched === null) {
                throw new \InvalidArgumentException('A supplied character-code string contains undefined or truncated codes.');
            }
            $codes[] = $matched;
            $offset += $matched->length();
        }
        return new CMapCodeSequence($this, ...$codes);
    }
}
