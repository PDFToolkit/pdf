<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\Encodings;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

final readonly class NamedFontEncoding implements FontEncoding
{
    private EncodingVector $vector;
    public function __construct(public PredefinedEncoding $encoding)
    {
        $this->vector = $encoding->vector();
    }
    public function object(): NameType
    {
        return new NameType($this->encoding->value);
    }
    public function glyph(IntegerType $code): NameType
    {
        return $this->vector->glyph($code);
    }
}
