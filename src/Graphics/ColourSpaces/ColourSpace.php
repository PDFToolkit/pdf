<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

use PDF\Functions\Range;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NameType;

/** Explicit PDF representation, component count and declared component intervals. */
interface ColourSpace
{
    public function value(): NameType|ArrayType;
    public function family(): ColourSpaceFamily;
    public function componentCount(): int;
    public function ranges(): ?Range;
}
