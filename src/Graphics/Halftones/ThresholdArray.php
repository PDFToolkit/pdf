<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Graphics\Patterns\Shadings\MeshBitWriter;

/** Actual 8/16-bit MSB threshold data, with exact count and bounded construction. */
final readonly class ThresholdArray
{
    public string $bytes;

    /** @param string|iterable<int> $samples */
    public function __construct(public int $bits, public int $count, string|iterable $samples, int $maximumBytes = 67108864)
    {
        if (!in_array($bits, [8, 16], true) || $count < 1 || $maximumBytes < 1 || $count > intdiv($maximumBytes, intdiv($bits, 8))) {
            throw new \InvalidArgumentException('Threshold arrays need positive bounded counts and 8/16-bit samples.');
        }
        if (is_string($samples)) {
            if (strlen($samples) !== $count * intdiv($bits, 8)) {
                throw new \InvalidArgumentException('Threshold bytes must exactly match their count and bit width.');
            }
            $this->bytes = $samples;
            return;
        }
        $writer = new MeshBitWriter($maximumBytes);
        $supplied = 0;
        foreach ($samples as $sample) {
            if (++$supplied > $count) {
                throw new \InvalidArgumentException('Too many threshold samples.');
            }
            $writer->write($sample, $bits);
        }
        if ($supplied !== $count) {
            throw new \InvalidArgumentException('Every declared threshold must have a sample.');
        }
        $this->bytes = $writer->bytes();
    }

    public function sample(int $index): int
    {
        if ($index < 0 || $index >= $this->count) {
            throw new \OutOfBoundsException('Threshold index is outside its array.');
        }
        return $this->bits === 8 ? ord($this->bytes[$index]) : (ord($this->bytes[2 * $index]) << 8) | ord($this->bytes[2 * $index + 1]);
    }
}
