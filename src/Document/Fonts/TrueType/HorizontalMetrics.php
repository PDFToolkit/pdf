<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** hmtx widths in font units, including the repeated final advance width. */
final readonly class HorizontalMetrics
{
    public function __construct(private BinaryReader $table, private int $glyphCount, private int $numberOfHMetrics, public int $unitsPerEm)
    {
        if ($glyphCount < 1 || $glyphCount > 65535 || $numberOfHMetrics < 1 || $numberOfHMetrics > $glyphCount || $unitsPerEm < 16 || $unitsPerEm > 16384) {
            throw new \InvalidArgumentException('Invalid TrueType metric counts or unitsPerEm.');
        }
        $table->slice(0, $numberOfHMetrics * 4 + ($glyphCount - $numberOfHMetrics) * 2);
    }

    public function advanceWidth(int $glyphId): int
    {
        if ($glyphId < 0 || $glyphId >= $this->glyphCount) {
            throw new \OutOfRangeException('Unknown TrueType glyph ID.');
        }
        return $this->table->uint16(min($glyphId, $this->numberOfHMetrics - 1) * 4);
    }

    public function pdfWidth(int $glyphId): float
    {
        return $this->scale($this->advanceWidth($glyphId));
    }

    public function scale(int $fontUnits): float
    {
        return $fontUnits * 1000 / $this->unitsPerEm;
    }
}
