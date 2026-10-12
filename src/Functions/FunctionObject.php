<?php

declare(strict_types=1);

namespace PDF\Functions;

use PDF\Syntax\FileStructure\BasicType;

interface FunctionObject extends BasicType
{
    public function domain(): Domain;

    public function range(): ?Range;

    public function inputCount(): int;

    public function outputCount(): int;
}
