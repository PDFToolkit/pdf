<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\IntegerType;

/** Per-row encoder choice; the actual filter tag always accompanies every PNG-predicted row. */
interface PngFilterSelection
{
    public function predictor(): IntegerType;

    public function select(string $row, string $previous, PredictorLayout $layout): PngRowFilter;
}
