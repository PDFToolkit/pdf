<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\DictionaryType;

interface Filter
{
    public function name(): NameType;
    public function parameters(): ?DictionaryType;
    public function encode(string $bytes): string;
}
