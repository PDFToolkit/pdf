<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;

/** ISO 7.4.4.4, physical pages 36–37: sample-wise horizontal differencing, reset for each row. */
final readonly class TiffPredictor implements Predictor
{
    public function __construct(public PredictorLayout $layout = new PredictorLayout())
    {
    }

    public function parameters(): DictionaryType
    {
        return $this->layout->parameters(new IntegerType(2));
    }

    public function encode(string $bytes): string
    {
        $this->layout->validateBytes($bytes);
        $bits = $this->layout->bitsPerComponent->value;
        $colors = $this->layout->colors->integer();
        $mask = (1 << $bits) - 1;
        $output = '';
        for ($start = 0; $start < strlen($bytes); $start += $this->layout->bytesPerRow) {
            $row = substr($bytes, $start, $this->layout->bytesPerRow);
            $encoded = $row; // Preserve the unused low padding bits of sub-byte rows.
            for ($sample = $colors; $sample < $this->layout->samplesPerRow; ++$sample) {
                if ($bits === 16) {
                    $position = $sample * 2;
                    $left = ($sample - $colors) * 2;
                    $value = ((ord($row[$position]) << 8) | ord($row[$position + 1])) - ((ord($row[$left]) << 8) | ord($row[$left + 1]));
                    $value &= $mask;
                    $encoded[$position] = chr($value >> 8);
                    $encoded[$position + 1] = chr($value & 255);
                } else {
                    $position = intdiv($sample * $bits, 8);
                    $shift = 8 - $bits - ($sample * $bits % 8);
                    $leftPosition = intdiv(($sample - $colors) * $bits, 8);
                    $leftShift = 8 - $bits - (($sample - $colors) * $bits % 8);
                    $value = ((ord($row[$position]) >> $shift) & $mask) - ((ord($row[$leftPosition]) >> $leftShift) & $mask);
                    $encoded[$position] = chr((ord($encoded[$position]) & ~(($mask) << $shift)) | (($value & $mask) << $shift));
                }
            }
            $output .= $encoded;
        }
        return $output;
    }
}
