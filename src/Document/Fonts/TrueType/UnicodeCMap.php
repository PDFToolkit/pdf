<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** A bounded Unicode cmap, using the preferred format 12 or format 4 subtable. */
final readonly class UnicodeCMap
{
    private BinaryReader $subtable;
    public int $format;
    /** @var list<array{start: int, end: int, delta: int, range: int, word: int}> */
    private array $segments;
    private int $groupCount;

    public function __construct(BinaryReader $table, private int $glyphCount)
    {
        if ($glyphCount < 1 || $glyphCount > 65535) {
            throw new \InvalidArgumentException('TrueType requires 1 to 65535 glyphs.');
        }
        if ($table->uint16(0) !== 0) {
            throw new \InvalidArgumentException('Unsupported cmap version.');
        }
        $records = $table->uint16(2);
        $table->slice(4, $records * 8);
        $chosen = null;
        $priority = -1;
        for ($index = 0; $index < $records; ++$index) {
            $record = 4 + $index * 8;
            $platform = $table->uint16($record);
            $encoding = $table->uint16($record + 2);
            $offset = $table->uint32($record + 4);
            if ($offset < 4 + $records * 8) {
                throw new \InvalidArgumentException('cmap subtable overlaps its encoding records.');
            }
            $format = $table->uint16($offset);
            if (!(($platform === 3 && in_array($encoding, [1, 10], true)) || ($platform === 0 && in_array($encoding, [0, 1, 2, 3, 4, 6], true)))) {
                continue;
            }
            if (!in_array($format, [4, 12], true)) {
                continue;
            }
            $rank = ($format === 12 ? 10 : 0) + ($platform === 3 ? 1 : 0);
            if ($rank > $priority) {
                $length = $format === 12 ? $table->uint32($offset + 4) : $table->uint16($offset + 2);
                $chosen = $table->view($offset, $length);
                $priority = $rank;
            }
        }
        if ($chosen === null) {
            throw new \InvalidArgumentException('A Unicode cmap format 4 or 12 is required.');
        }
        $this->subtable = $chosen;
        $this->format = $chosen->uint16(0);
        $segments = [];
        $groupCount = 0;
        if ($this->format === 12) {
            if ($chosen->uint16(2) !== 0) {
                throw new \InvalidArgumentException('Invalid cmap format 12 reserved field.');
            }
            $count = $chosen->uint32(12);
            if ($count > intdiv(max(0, $chosen->length() - 16), 12)) {
                throw new \InvalidArgumentException('Truncated cmap format 12 groups.');
            }
            $last = -1;
            for ($index = 0; $index < $count; ++$index) {
                $offset = 16 + $index * 12;
                $start = $chosen->uint32($offset);
                $end = $chosen->uint32($offset + 4);
                $glyph = $chosen->uint32($offset + 8);
                if ($start <= $last || $end < $start || $end > 0x10FFFF || ($start <= 0xDFFF && $end >= 0xD800) || $glyph + $end - $start >= $glyphCount) {
                    throw new \InvalidArgumentException('Invalid cmap format 12 character or glyph range.');
                }
                $last = $end;
            }
            // Keep groups in the binary table, without per-group PHP allocations.
            $groupCount = $count;
        } else {
            $twiceCount = $chosen->uint16(6);
            if ($twiceCount === 0 || $twiceCount % 2 !== 0) {
                throw new \InvalidArgumentException('Invalid cmap format 4 segment count.');
            }
            $count = intdiv($twiceCount, 2);
            $chosen->slice(0, 16 + $count * 8);
            if ($chosen->uint16(14 + $count * 2) !== 0) {
                throw new \InvalidArgumentException('Invalid cmap format 4 reserved field.');
            }
            $last = -1;
            for ($index = 0; $index < $count; ++$index) {
                $end = $chosen->uint16(14 + $index * 2);
                $start = $chosen->uint16(16 + $count * 2 + $index * 2);
                $delta = $chosen->int16(16 + $count * 4 + $index * 2);
                $word = 16 + $count * 6 + $index * 2;
                $range = $chosen->uint16($word);
                if ($start <= $last || $end < $start || ($range !== 0 && ($range % 2 !== 0 || $word + $range < 16 + $count * 8))) {
                    throw new \InvalidArgumentException('Invalid cmap format 4 segment.');
                }
                if ($range !== 0) {
                    $chosen->slice($word + $range, ($end - $start + 1) * 2);
                }
                // Each BMP code is visited at most once across disjoint segments.
                for ($point = $start; $point <= $end; ++$point) {
                    $glyph = $range === 0 ? $point : $chosen->uint16($word + $range + ($point - $start) * 2);
                    $glyph = $range !== 0 && $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                    if ($glyph >= $glyphCount || ($glyph !== 0 && $point >= 0xD800 && $point <= 0xDFFF)) {
                        throw new \InvalidArgumentException('cmap references a nonexistent glyph.');
                    }
                }
                $segments[] = ['start' => $start, 'end' => $end, 'delta' => $delta, 'range' => $range, 'word' => $word];
                $last = $end;
            }
            if ($last !== 0xFFFF) {
                throw new \InvalidArgumentException('cmap format 4 needs its terminal FFFF segment.');
            }
        }
        $this->segments = $segments;
        $this->groupCount = $groupCount;
    }

    public function glyphId(int $codePoint): int
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            throw new \InvalidArgumentException('An individual Unicode scalar value is required.');
        }
        // Binary search prevents repertoire size from multiplying table size.
        $low = 0;
        $high = ($this->format === 12 ? $this->groupCount : count($this->segments)) - 1;
        while ($low <= $high) {
            $index = intdiv($low + $high, 2);
            $start = $this->format === 12 ? $this->subtable->uint32(16 + $index * 12) : $this->segments[$index]['start'];
            $end = $this->format === 12 ? $this->subtable->uint32(20 + $index * 12) : $this->segments[$index]['end'];
            if ($codePoint < $start) {
                $high = $index - 1;
            } elseif ($codePoint > $end) {
                $low = $index + 1;
            } else {
                if ($this->format === 12) {
                    return $this->subtable->uint32(24 + $index * 12) + $codePoint - $start;
                }
                $segment = $this->segments[$index];
                $glyph = $segment['range'] === 0 ? $codePoint : $this->subtable->uint16($segment['word'] + $segment['range'] + ($codePoint - $segment['start']) * 2);
                $glyph = $segment['range'] !== 0 && $glyph === 0 ? 0 : ($glyph + $segment['delta']) & 0xFFFF;
                if ($glyph >= $this->glyphCount) {
                    throw new \InvalidArgumentException('cmap references a nonexistent glyph.');
                }
                return $glyph;
            }
        }
        return 0;
    }
}
