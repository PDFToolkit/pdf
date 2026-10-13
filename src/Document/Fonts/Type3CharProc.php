<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\NameType;

final readonly class Type3CharProc
{
    public function __construct(public NameType $name, public Type3Glyph $glyph)
    {
        if ($name->value === '') {
            throw new \InvalidArgumentException('A Type 3 CharProc has a nonempty glyph name.');
        }
    }
}
