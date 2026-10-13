<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\DictionaryType;

/** The explicit PDF-native sample transform applied before LZW/Flate compression. */
interface Predictor
{
    public function encode(string $bytes): string;

    public function parameters(): DictionaryType;
}
