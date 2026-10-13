<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\NameType;

/** The exact BaseFont/FontName name of an explicitly declared subset. */
final readonly class SubsetFontName
{
    public function __construct(public FontSubsetTag $tag, public NameType $original)
    {
        if ($original->value === '' || str_contains($original->value, '+')) {
            throw new \InvalidArgumentException('A subset has a nonempty original font name without a subset prefix.');
        }
    }

    public function name(): NameType
    {
        return new NameType($this->tag->value . '+' . $this->original->value);
    }
}
