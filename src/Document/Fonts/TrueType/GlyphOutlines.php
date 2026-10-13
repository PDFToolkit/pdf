<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** Structural glyf/loca validation; TrueType instructions are never executed. */
final readonly class GlyphOutlines
{
    public function __construct(private BinaryReader $glyf, private BinaryReader $loca, private int $format, private int $glyphCount)
    {
        if (!in_array($format, [0, 1], true) || $glyphCount < 1 || $glyphCount > 65535) {
            throw new \InvalidArgumentException('Invalid loca offset format.');
        }
        $loca->slice(0, ($glyphCount + 1) * ($format === 0 ? 2 : 4));
        $previous = 0;
        $records = '';
        $edges = '';
        $edgeCount = 0;
        for ($glyph = 0; $glyph <= $glyphCount; ++$glyph) {
            $offset = $format === 0 ? $loca->uint16($glyph * 2) * 2 : $loca->uint32($glyph * 4);
            if ($offset < $previous || $offset > $glyf->length() || ($glyph === 0 && $offset !== 0)) {
                throw new \InvalidArgumentException('Invalid loca glyph extent.');
            }
            if ($glyph > 0) {
                $outline = $glyf->view($previous, $offset - $previous);
                $dependencies = [];
                if ($outline->length() !== 0) {
                    $contours = $outline->int16(0);
                    $box = [$outline->int16(2), $outline->int16(4), $outline->int16(6), $outline->int16(8)];
                    if ($box[0] > $box[2] || $box[1] > $box[3]) {
                        throw new \InvalidArgumentException('Invalid glyph bounding box.');
                    }
                    if ($contours >= 0) {
                        $this->validateSimple($outline, $contours);
                    } elseif ($contours === -1) {
                        $dependencies = $this->validateComposite($outline, $glyphCount);
                        if ($edgeCount + count($dependencies) > 65535) {
                            throw new \InvalidArgumentException('A font is limited to 65535 composite glyph references.');
                        }
                    } else {
                        throw new \InvalidArgumentException('Invalid glyph contour count.');
                    }
                }
                // Six bytes per glyph and two bytes per dependency, without
                // allocating a PHP array for every glyph in an untrusted font.
                $records .= pack('Nn', $edgeCount, count($dependencies));
                if ($dependencies !== []) {
                    $edges .= pack('n*', ...$dependencies);
                    $edgeCount += count($dependencies);
                }
            }
            $previous = $offset;
        }
        $graph = new BinaryReader($records);
        $edgeData = new BinaryReader($edges);
        $states = str_repeat("\x00", $glyphCount);
        $depths = str_repeat("\x00", $glyphCount);
        for ($glyph = 0; $glyph < $glyphCount; ++$glyph) {
            $this->visit($glyph, $graph, $edgeData, $states, $depths, 0);
        }
    }

    /** @return array{int, int, int, int}|null */
    public function boundingBox(int $glyphId): ?array
    {
        if ($glyphId < 0 || $glyphId >= $this->glyphCount) {
            throw new \OutOfRangeException('Unknown TrueType glyph ID.');
        }
        $start = $this->format === 0 ? $this->loca->uint16($glyphId * 2) * 2 : $this->loca->uint32($glyphId * 4);
        $end = $this->format === 0 ? $this->loca->uint16(($glyphId + 1) * 2) * 2 : $this->loca->uint32(($glyphId + 1) * 4);
        return $start === $end ? null : [$this->glyf->int16($start + 2), $this->glyf->int16($start + 4), $this->glyf->int16($start + 6), $this->glyf->int16($start + 8)];
    }

    private function validateSimple(BinaryReader $outline, int $contours): void
    {
        if ($contours === 0 && $outline->length() === 10) {
            return;
        }
        $previous = -1;
        for ($index = 0; $index < $contours; ++$index) {
            $end = $outline->uint16(10 + $index * 2);
            if ($end <= $previous) {
                throw new \InvalidArgumentException('Simple glyph contour endpoints must increase.');
            }
            $previous = $end;
        }
        $offset = 10 + $contours * 2;
        $instructions = $outline->uint16($offset);
        $outline->slice($offset + 2, $instructions);
        $offset += 2 + $instructions;
        $points = $previous + 1;
        $coordinateBytes = 0;
        for ($point = 0; $point < $points;) {
            $flag = ord($outline->slice($offset++, 1));
            if (($flag & 0x80) !== 0 || ($point !== 0 && ($flag & 0x40) !== 0)) {
                throw new \InvalidArgumentException('Invalid simple glyph flag bits.');
            }
            $repeat = ($flag & 8) !== 0 ? ord($outline->slice($offset++, 1)) + 1 : 1;
            if ($repeat > $points - $point) {
                throw new \InvalidArgumentException('Glyph flag repeats exceed the number of points.');
            }
            $coordinateBytes += $repeat * ((($flag & 2) !== 0 ? 1 : (($flag & 16) !== 0 ? 0 : 2)) + (($flag & 4) !== 0 ? 1 : (($flag & 32) !== 0 ? 0 : 2)));
            $point += $repeat;
        }
        $outline->slice($offset, $coordinateBytes);
    }

    /** @return list<int> */
    private function validateComposite(BinaryReader $outline, int $glyphCount): array
    {
        $offset = 10;
        $components = [];
        $instructions = false;
        do {
            $flags = $outline->uint16($offset);
            $glyph = $outline->uint16($offset + 2);
            if ($glyph >= $glyphCount || count($components) >= 1024 || ($flags & 0xE010) !== 0 || ($flags & 0x1800) === 0x1800 || ($components === [] && ($flags & 2) === 0)) {
                throw new \InvalidArgumentException('Invalid composite glyph flags, reference or component count.');
            }
            $transforms = (int) (($flags & 8) !== 0) + (int) (($flags & 64) !== 0) + (int) (($flags & 128) !== 0);
            if ($transforms > 1) {
                throw new \InvalidArgumentException('Composite glyph transform flags are mutually exclusive.');
            }
            $size = 4 + (($flags & 1) !== 0 ? 4 : 2) + (($flags & 8) !== 0 ? 2 : (($flags & 64) !== 0 ? 4 : (($flags & 128) !== 0 ? 8 : 0)));
            $outline->slice($offset, $size);
            $offset += $size;
            $components[] = $glyph;
            $instructions = $instructions || ($flags & 256) !== 0;
        } while (($flags & 32) !== 0);
        if ($instructions) {
            $length = $outline->uint16($offset);
            $outline->slice($offset + 2, $length);
        }
        return $components;
    }

    private function visit(int $glyph, BinaryReader $graph, BinaryReader $edges, string &$states, string &$depths, int $depth): int
    {
        $state = ord($states[$glyph]);
        if ($depth > 64 || $state === 1) {
            throw new \InvalidArgumentException('Composite glyph graph is cyclic or exceeds depth 64.');
        }
        if ($state === 2) {
            $height = ord($depths[$glyph]);
            if ($depth + $height > 64) {
                throw new \InvalidArgumentException('Composite glyph graph exceeds depth 64.');
            }
            return $height;
        }
        $states[$glyph] = "\x01";
        $height = 0;
        $firstEdge = $graph->uint32($glyph * 6);
        $edgeCount = $graph->uint16($glyph * 6 + 4);
        for ($index = 0; $index < $edgeCount; ++$index) {
            $child = $edges->uint16(($firstEdge + $index) * 2);
            $height = max($height, 1 + $this->visit($child, $graph, $edges, $states, $depths, $depth + 1));
        }
        if ($height > 64) {
            throw new \InvalidArgumentException('Composite glyph graph exceeds depth 64.');
        }
        $states[$glyph] = "\x02";
        $depths[$glyph] = chr($height);
        return $height;
    }
}
