<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\IntegerType;

/** Predictor15 uses a deterministic residual-magnitude heuristic, not a smallest-deflate-size claim. */
final readonly class AdaptivePngFilterSelection implements PngFilterSelection
{
    public function predictor(): IntegerType
    {
        return new IntegerType(15);
    }

    public function select(string $row, string $previous, PredictorLayout $layout): PngRowFilter
    {
        $selected = PngRowFilter::None;
        $leastScore = null;
        foreach (PngRowFilter::cases() as $filter) {
            $candidate = $filter->encode($row, $previous, $layout);
            $score = 0;
            for ($position = 0; $position < strlen($candidate); ++$position) {
                $byte = ord($candidate[$position]);
                $score += min($byte, 256 - $byte);
            }
            if ($leastScore === null || $score < $leastScore) {
                $selected = $filter;
                $leastScore = $score;
            }
        }
        return $selected;
    }
}
