<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Specification\Dictionary\LZWDecodeAndFlateDecodeFiltersTable8;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Explicit interleaved PDF sample rows, not a parser for PNG or TIFF files. */
final readonly class PredictorLayout
{
    public int $samplesPerRow;
    public int $bytesPerRow;
    public int $bytesPerPixel;

    public function __construct(
        public IntegerType $colors = new IntegerType(1),
        public IntegerType $columns = new IntegerType(1),
        public PredictorBitsPerComponent $bitsPerComponent = PredictorBitsPerComponent::Eight,
    ) {
        $channels = $colors->integer();
        $width = $columns->integer();
        if ($channels < 1 || $width < 1) {
            throw new \InvalidArgumentException('PDF predictor Colors and Columns are positive integers.');
        }
        if ($channels > intdiv(PHP_INT_MAX, $width) || $channels * $width > intdiv(PHP_INT_MAX, $bitsPerComponent->value)) {
            throw new \OverflowException('The predictor row sample/bit count exceeds the integer address space.');
        }
        $this->samplesPerRow = $channels * $width;
        $rowBits = $this->samplesPerRow * $bitsPerComponent->value;
        $pixelBits = $channels * $bitsPerComponent->value;
        $this->bytesPerRow = intdiv($rowBits, 8) + ($rowBits % 8 === 0 ? 0 : 1);
        $this->bytesPerPixel = intdiv($pixelBits, 8) + ($pixelBits % 8 === 0 ? 0 : 1);
    }

    public function validateBytes(string $bytes): void
    {
        if (strlen($bytes) % $this->bytesPerRow !== 0) {
            throw new \InvalidArgumentException('Predictor data must contain whole byte-padded rows for its explicit layout.');
        }
    }

    public function parameters(IntegerType $predictor): DictionaryType
    {
        if (!in_array($predictor->integer(), [2, 10, 11, 12, 13, 14, 15], true)) {
            throw new \InvalidArgumentException('Row layout parameters belong only to TIFF or PNG prediction.');
        }
        return new LZWDecodeAndFlateDecodeFiltersTable8(
            new Entry(new NameType('Predictor'), $predictor),
            new Entry(new NameType('Colors'), $this->colors),
            new Entry(new NameType('Columns'), $this->columns),
            new Entry(new NameType('BitsPerComponent'), new IntegerType($this->bitsPerComponent->value)),
        );
    }
}
