<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns\Shadings;

use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Syntax\FileStructure\BasicType;

/** The PDF object and colour contract shared by dictionary and stream shadings (table 78). */
interface ShadingObject extends BasicType
{
    public function colourSpace(): ColourSpace;
    public function shadingType(): int;
}
