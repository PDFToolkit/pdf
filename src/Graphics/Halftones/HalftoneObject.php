<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Syntax\FileStructure\BasicType;

interface HalftoneObject extends BasicType
{
    public function halftoneType(): int;
    public function hasTransferFunction(): bool;
}
