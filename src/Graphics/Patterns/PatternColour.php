<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns;

use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Graphics\ColourSpaces\Pattern;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingColorWithPattern;
use PDF\Syntax\ContentStreamsResources\Operators\SetStrokingColorWithPattern;
use PDF\Syntax\Object\NameType;

/** Validated coloured/stencil pattern selection and SCN/scn operands (8.7.3.2–3). */
final readonly class PatternColour
{
    public function __construct(public Pattern $space, public NameType $resourceName, public TilingPattern|ShadingPattern $pattern, public ?ColourComponents $underlyingColour = null)
    {
        $stencil = $pattern instanceof TilingPattern && $pattern->paintType === PaintType::Uncoloured;
        if ($stencil) {
            if ($space->underlying === null || $underlyingColour === null || $underlyingColour->space !== $space->underlying) {
                throw new \InvalidArgumentException('A stencil pattern requires its underlying colour space and matching component values.');
            }
        } elseif ($space->underlying !== null || $underlyingColour !== null) {
            throw new \InvalidArgumentException('A coloured tiling/shading pattern cannot receive underlying colour components.');
        }
    }

    public function nonstroking(): SetNonstrokingColorWithPattern
    {
        return new SetNonstrokingColorWithPattern(...[...($this->underlyingColour === null ? [] : $this->underlyingColour->numbers), $this->resourceName]);
    }

    public function stroking(): SetStrokingColorWithPattern
    {
        return new SetStrokingColorWithPattern(...[...($this->underlyingColour === null ? [] : $this->underlyingColour->numbers), $this->resourceName]);
    }
}
