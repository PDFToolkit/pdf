<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** ISO 9.7.6.2–3, pp.288–289: normal decoding and shortest/longest partial-match fallback. */
final readonly class CMapDecoder
{
    public function __construct(private CodeSpaces $spaces)
    {
        if ($spaces->ranges === []) {
            throw new \InvalidArgumentException('Decoding requires a nonempty codespace.');
        }
    }

    /** @return list<DecodedCMapCode> */
    public function decode(HexadecimalString $string): array
    {
        $bytes = $string->value;
        $result = [];
        for ($offset = 0; $offset < strlen($bytes);) {
            $defined = false;
            $code = null;
            for ($length = 1; $length <= min(4, strlen($bytes) - $offset); ++$length) {
                $candidate = new CMapCode(new HexadecimalString(substr($bytes, $offset, $length)));
                if ($this->spaces->contains($candidate)) {
                    $code = $candidate;
                    $defined = true;
                    break;
                }
            }
            if ($code === null) {
                $bestPrefix = -1;
                $length = 5;
                foreach ($this->spaces->ranges as $range) {
                    $prefix = 0;
                    for ($index = 0; $index < min($range->low->length(), strlen($bytes) - $offset); ++$index) {
                        $byte = ord($bytes[$offset + $index]);
                        if ($byte < ord($range->low->value->value[$index]) || $byte > ord($range->high->value->value[$index])) {
                            break;
                        }
                        ++$prefix;
                    }
                    if ($prefix > $bestPrefix || ($prefix === $bestPrefix && $range->low->length() < $length)) {
                        $bestPrefix = $prefix;
                        $length = $range->low->length();
                    }
                }
                $code = new CMapCode(new HexadecimalString(substr($bytes, $offset, min($length, strlen($bytes) - $offset))));
            }
            $result[] = new DecodedCMapCode($code, new IntegerType($length), $defined);
            $offset += $code->length();
        }
        return $result;
    }
}
