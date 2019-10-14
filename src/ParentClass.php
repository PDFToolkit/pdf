<?php

declare(strict_types=1);

namespace PDF;

use PDF\Syntax\FileStructure\BasicType;

interface ParentClass
{
    public function parent(): ?BasicType;

    public function setParent(BasicType $parent): void;
}
