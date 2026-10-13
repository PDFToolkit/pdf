<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

/** PDF Table 9 and referenced RFC2083 section6: byte predictors, including 16-bit samples. */
enum PngRowFilter: int
{
    case None = 0;
    case Sub = 1;
    case Up = 2;
    case Average = 3;
    case Paeth = 4;

    public function encode(string $row, string $previous, PredictorLayout $layout): string
    {
        if (strlen($row) !== $layout->bytesPerRow || strlen($previous) !== $layout->bytesPerRow) {
            throw new \InvalidArgumentException('A PNG predictor works on two whole rows of the same declared layout.');
        }
        if ($this === self::None) {
            return $row;
        }
        $output = '';
        $stride = $layout->bytesPerPixel;
        for ($position = 0; $position < strlen($row); ++$position) {
            $left = $position < $stride ? 0 : ord($row[$position - $stride]);
            $above = ord($previous[$position]);
            $upperLeft = $position < $stride ? 0 : ord($previous[$position - $stride]);
            $prediction = match ($this) {
                self::Sub => $left,
                self::Up => $above,
                self::Average => intdiv($left + $above, 2),
                self::Paeth => $this->paeth($left, $above, $upperLeft),
            };
            $output .= chr((ord($row[$position]) - $prediction) & 255);
        }
        return $output;
    }

    private function paeth(int $left, int $above, int $upperLeft): int
    {
        $estimate = $left + $above - $upperLeft;
        $leftDistance = abs($estimate - $left);
        $aboveDistance = abs($estimate - $above);
        $upperLeftDistance = abs($estimate - $upperLeft);
        return $leftDistance <= $aboveDistance && $leftDistance <= $upperLeftDistance ? $left : ($aboveDistance <= $upperLeftDistance ? $above : $upperLeft);
    }
}
