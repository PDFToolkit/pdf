<?php

declare(strict_types=1);

namespace PDF\Security;

/**
 * RFC 4013 stored-string SASLprep: mapping, Unicode 3.2 NFKC, prohibitions and BIDI.
 * No locale, installed ICU version or optional Unicode extension affects the result.
 * The R=5 byte truncation is applied after preparation by PreparedPassword.
 */
final readonly class Saslprep
{
    public function __construct(private Unicode32SaslprepTables $tables = new Unicode32SaslprepTables())
    {
    }

    public function prepare(#[\SensitiveParameter] string $unicodeInput): PreparedPassword
    {
        return new PreparedPassword($this->normalize($unicodeInput));
    }

    public function normalize(#[\SensitiveParameter] string $unicodeInput): string
    {
        $mapped = [];
        foreach ($this->decode($unicodeInput) as $point) {
            if ($this->tables->contains('mappedToNothing', $point)) {
                continue;
            }
            $mapped[] = $this->tables->contains('nonAsciiSpace', $point) ? 32 : $point;
        }
        $decomposed = [];
        foreach ($mapped as $point) {
            $this->decompose($point, $decomposed);
        }
        $normalized = $this->compose($this->canonicalOrder($decomposed));
        $hasRandAL = false;
        $hasLeftToRight = false;
        $result = '';
        foreach ($normalized as $point) {
            if ($this->tables->contains('prohibited', $point) || $this->tables->contains('unassigned', $point)) {
                throw new \InvalidArgumentException('The password contains a prohibited or unassigned Unicode 3.2 character.');
            }
            $hasRandAL = $hasRandAL || $this->tables->contains('randAL', $point);
            $hasLeftToRight = $hasLeftToRight || $this->tables->contains('leftToRight', $point);
            $result .= $this->encode($point);
        }
        if ($hasRandAL && ($hasLeftToRight || !$this->tables->contains('randAL', $normalized[0])
            || !$this->tables->contains('randAL', $normalized[count($normalized) - 1]))) {
            throw new \InvalidArgumentException('The password violates the SASLprep bidirectional-text rule.');
        }
        return $result;
    }

    /** @return list<int> */
    private function decode(string $bytes): array
    {
        if (preg_match('//u', $bytes) !== 1) {
            throw new \InvalidArgumentException('A password must contain valid UTF-8.');
        }
        $result = [];
        for ($index = 0, $length = strlen($bytes); $index < $length; ++$index) {
            $first = ord($bytes[$index]);
            if ($first < 0x80) {
                $result[] = $first;
                continue;
            }
            $following = $first < 0xe0 ? 1 : ($first < 0xf0 ? 2 : 3);
            $point = $first & (0x7f >> $following);
            for ($part = 0; $part < $following; ++$part) {
                $point = ($point << 6) | (ord($bytes[++$index]) & 0x3f);
            }
            $result[] = $point;
        }
        return $result;
    }

    private function encode(int $point): string
    {
        return match (true) {
            $point < 0x80 => chr($point),
            $point < 0x800 => chr(0xc0 | ($point >> 6)) . chr(0x80 | ($point & 0x3f)),
            $point < 0x10000 => chr(0xe0 | ($point >> 12)) . chr(0x80 | (($point >> 6) & 0x3f)) . chr(0x80 | ($point & 0x3f)),
            default => chr(0xf0 | ($point >> 18)) . chr(0x80 | (($point >> 12) & 0x3f)) . chr(0x80 | (($point >> 6) & 0x3f)) . chr(0x80 | ($point & 0x3f)),
        };
    }

    /** @param list<int> $output */
    private function decompose(int $point, array &$output): void
    {
        // Hangul syllables are algorithmic and absent from UnicodeData.txt.
        if ($point >= 0xac00 && $point <= 0xd7a3) {
            $syllable = $point - 0xac00;
            $output[] = 0x1100 + intdiv($syllable, 588);
            $output[] = 0x1161 + intdiv($syllable % 588, 28);
            if ($syllable % 28 !== 0) {
                $output[] = 0x11a7 + $syllable % 28;
            }
            return;
        }
        if (isset($this->tables->decomposition[$point])) {
            foreach ($this->tables->decomposition[$point] as $part) {
                $this->decompose($part, $output);
            }
        } else {
            $output[] = $point;
        }
    }

    /** Stable rank buckets avoid quadratic insertion sorting on hostile combining-mark runs.
     * @param list<int> $points
     * @return list<int>
     */
    private function canonicalOrder(array $points): array
    {
        $output = [];
        $marks = [];
        foreach ($points as $point) {
            $rank = $this->tables->combining[$point] ?? 0;
            if ($rank === 0) {
                ksort($marks, SORT_NUMERIC);
                foreach ($marks as $bucket) {
                    foreach ($bucket as $mark) {
                        $output[] = $mark;
                    }
                }
                $marks = [];
                $output[] = $point;
            } else {
                $marks[$rank][] = $point;
            }
        }
        ksort($marks, SORT_NUMERIC);
        foreach ($marks as $bucket) {
            foreach ($bucket as $mark) {
                $output[] = $mark;
            }
        }
        return $output;
    }

    /** @param list<int> $points
     * @return list<int>
     */
    private function compose(array $points): array
    {
        if ($points === []) {
            return [];
        }
        $output = [$points[0]];
        $starter = 0;
        $lastRank = $this->tables->combining[$points[0]] ?? 0;
        foreach (array_slice($points, 1) as $point) {
            $rank = $this->tables->combining[$point] ?? 0;
            $pair = $this->composition($output[$starter], $point);
            if ($pair !== null && ($lastRank < $rank || $lastRank === 0)) {
                $output[$starter] = $pair;
            } else {
                if ($rank === 0) {
                    $starter = count($output);
                }
                $output[] = $point;
                $lastRank = $rank;
            }
        }
        return array_values($output);
    }

    private function composition(int $starter, int $point): ?int
    {
        if ($starter >= 0x1100 && $starter <= 0x1112 && $point >= 0x1161 && $point <= 0x1175) {
            return 0xac00 + ($starter - 0x1100) * 588 + ($point - 0x1161) * 28;
        }
        if ($starter >= 0xac00 && $starter <= 0xd7a3 && ($starter - 0xac00) % 28 === 0 && $point >= 0x11a8 && $point <= 0x11c2) {
            return $starter + $point - 0x11a7;
        }
        return $this->tables->composition[$starter . ':' . $point] ?? null;
    }
}
