<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** A complete static TrueType sfnt program; bytes are supplied by the application. */
final readonly class FontProgram
{
    /** @var array<string, BinaryReader> */
    private array $tables;
    public HorizontalMetrics $metrics;
    public UnicodeCMap $cmap;
    public GlyphOutlines $outlines;
    public int $glyphCount;
    public string $postScriptName;
    /** @var array{int, int, int, int} */
    public array $boundingBox;
    public int $ascent;
    public int $descent;
    public ?int $capHeight;
    public float $italicAngle;
    public bool $fixedPitch;
    public int $embeddingRights;

    public function __construct(public string $bytes, public FontProgramLimits $limits = new FontProgramLimits())
    {
        if (PHP_INT_SIZE < 8 || strlen($bytes) > $limits->maxBytes) {
            throw new \InvalidArgumentException('TrueType programs require 64-bit PHP and must respect the supplied byte budget.');
        }
        $reader = new BinaryReader($bytes);
        if ($reader->slice(0, 4) !== "\x00\x01\x00\x00") {
            throw new \InvalidArgumentException('Only a single static TrueType sfnt with glyf outlines is supported.');
        }
        $count = $reader->uint16(4);
        if ($count < 1 || $count > 256) {
            throw new \InvalidArgumentException('Invalid TrueType table count.');
        }
        $directoryEnd = 12 + $count * 16;
        $reader->slice(0, $directoryEnd);
        $tables = [];
        $ranges = [];
        for ($index = 0; $index < $count; ++$index) {
            $entry = 12 + $index * 16;
            $tag = $reader->slice($entry, 4);
            $offset = $reader->uint32($entry + 8);
            $length = $reader->uint32($entry + 12);
            if (isset($tables[$tag]) || $offset < $directoryEnd || $offset % 4 !== 0) {
                throw new \InvalidArgumentException('Duplicate, overlapping or unaligned sfnt table directory entry.');
            }
            $tables[$tag] = $reader->view($offset, $length);
            if ($length !== 0) {
                $ranges[] = [$offset, $offset + $length];
            }
        }
        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $last = $directoryEnd;
        foreach ($ranges as [$start, $end]) {
            if ($start < $last) {
                throw new \InvalidArgumentException('TrueType tables overlap.');
            }
            $last = $end;
        }
        foreach (['fvar', 'gvar', 'avar', 'cvar', 'HVAR', 'VVAR', 'MVAR', 'CFF ', 'CFF2', 'VARC'] as $unsupported) {
            if (isset($tables[$unsupported])) {
                throw new \InvalidArgumentException('Variable or CFF font programs are not supported.');
            }
        }
        $this->tables = $tables;
        $head = $this->table('head');
        $head->slice(0, 54);
        if ($head->uint32(0) !== 0x00010000 || $head->uint32(12) !== 0x5F0F3CF5 || $head->int16(52) !== 0) {
            throw new \InvalidArgumentException('Invalid TrueType head table.');
        }
        $maxp = $this->table('maxp');
        $maxp->slice(0, 32);
        if ($maxp->uint32(0) !== 0x00010000) {
            throw new \InvalidArgumentException('TrueType maxp version 1 is required.');
        }
        $this->glyphCount = $maxp->uint16(4);
        $hhea = $this->table('hhea');
        $hhea->slice(0, 36);
        if ($hhea->uint32(0) !== 0x00010000 || $hhea->int16(32) !== 0) {
            throw new \InvalidArgumentException('Invalid TrueType hhea table.');
        }
        $this->metrics = new HorizontalMetrics($this->table('hmtx'), $this->glyphCount, $hhea->uint16(34), $head->uint16(18));
        $box = [$head->int16(36), $head->int16(38), $head->int16(40), $head->int16(42)];
        if ($box[0] > $box[2] || $box[1] > $box[3]) {
            throw new \InvalidArgumentException('Invalid font bounding box.');
        }
        $this->boundingBox = $box;
        $os2 = $this->table('OS/2');
        $version = $os2->uint16(0);
        $minimumLength = match ($version) {
            0 => 78, 1 => 86, 2, 3, 4 => 96, 5 => 100, default => throw new \InvalidArgumentException('Unsupported OS/2 version.')
        };
        $os2->slice(0, $minimumLength);
        $rights = $os2->uint16(8);
        if (($rights & 0x0202) !== 0 || ($rights & ~0x030E) !== 0 || count(array_filter([($rights & 2) !== 0, ($rights & 4) !== 0, ($rights & 8) !== 0])) > 1) {
            throw new \InvalidArgumentException('Font embedding is restricted, bitmap-only, or has invalid embedding permissions.');
        }
        // No-subsetting (0100) is respected: this implementation embeds all glyphs.
        $this->embeddingRights = $rights;
        $this->ascent = $os2->int16(68);
        $this->descent = $os2->int16(70);
        if ($this->ascent < 0 || $this->descent > 0) {
            throw new \InvalidArgumentException('Invalid typographic ascent or descent.');
        }
        $post = $this->table('post');
        $post->slice(0, 32);
        $this->italicAngle = $post->int32(4) / 65536;
        $this->fixedPitch = $post->uint32(12) !== 0;
        $this->postScriptName = $this->readPostScriptName($this->table('name'));
        $this->cmap = new UnicodeCMap($this->table('cmap'), $this->glyphCount);
        $this->outlines = new GlyphOutlines($this->table('glyf'), $this->table('loca'), $head->int16(50), $this->glyphCount);
        $capitalH = $this->cmap->glyphId(0x48);
        $capitalTop = $capitalH === 0 ? null : ($this->outlines->boundingBox($capitalH)[3] ?? null);
        $this->capHeight = $version >= 2 ? $os2->int16(88) : $capitalTop;
    }

    public function table(string $tag): BinaryReader
    {
        return $this->tables[$tag] ?? throw new \InvalidArgumentException('Required TrueType table is missing: ' . $tag);
    }

    /** @return iterable<string, BinaryReader> */
    public function tables(): iterable
    {
        yield from $this->tables;
    }

    private function readPostScriptName(BinaryReader $table): string
    {
        $format = $table->uint16(0);
        $count = $table->uint16(2);
        $storage = $table->uint16(4);
        if (!in_array($format, [0, 1], true) || $storage < 6 + $count * 12) {
            throw new \InvalidArgumentException('Invalid TrueType naming table.');
        }
        $table->slice(6, $count * 12);
        if ($format === 1) {
            $tagCountOffset = 6 + $count * 12;
            $tagCount = $table->uint16($tagCountOffset);
            if ($storage < $tagCountOffset + 2 + $tagCount * 4) {
                throw new \InvalidArgumentException('Naming table strings overlap language tag records.');
            }
            for ($index = 0; $index < $tagCount; ++$index) {
                $tagOffset = $tagCountOffset + 2 + $index * 4;
                $table->slice($storage + $table->uint16($tagOffset + 2), $table->uint16($tagOffset));
            }
        }
        $result = null;
        $rank = -1;
        for ($index = 0; $index < $count; ++$index) {
            $offset = 6 + $index * 12;
            $platform = $table->uint16($offset);
            $encoding = $table->uint16($offset + 2);
            $nameId = $table->uint16($offset + 6);
            $bytes = $table->slice($storage + $table->uint16($offset + 10), $table->uint16($offset + 8));
            if ($nameId !== 6) {
                continue;
            }
            $candidate = null;
            $candidateRank = -1;
            if ($platform === 0 || ($platform === 3 && in_array($encoding, [1, 10], true))) {
                $candidate = @iconv('UTF-16BE', 'UTF-8', $bytes);
                $candidateRank = $platform === 3 ? 2 : 1;
            } elseif ($platform === 1 && $encoding === 0) {
                $candidate = $bytes;
                $candidateRank = 0;
            }
            if ($candidateRank > $rank) {
                if (!is_string($candidate) || !preg_match('/^[\x21-\x7E]{1,63}$/D', $candidate) || strpbrk($candidate, '[](){}<>/%') !== false) {
                    throw new \InvalidArgumentException('A valid ASCII PostScript font name is required.');
                }
                $result = $candidate;
                $rank = $candidateRank;
            }
        }
        return $result ?? throw new \InvalidArgumentException('The font has no supported PostScript name record.');
    }
}
