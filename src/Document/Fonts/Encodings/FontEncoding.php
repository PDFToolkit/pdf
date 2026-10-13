<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\Encodings;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

interface FontEncoding
{
    public function object(): NameType|DictionaryType;
    public function glyph(IntegerType $code): NameType;
}
