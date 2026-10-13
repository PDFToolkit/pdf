<?php

declare(strict_types=1);

namespace PDF\Graphics\ColourSpaces;

/** Pattern family, with an explicit non-Pattern underlying space for uncoloured tiling (page 164/187). */
final class Pattern extends ArrayColourSpace
{
    public function __construct(public readonly ?ColourSpace $underlying = null)
    {
        if ($underlying?->family() === ColourSpaceFamily::Pattern) {
            throw new \InvalidArgumentException('A Pattern underlying colour space cannot itself be Pattern.');
        }
        parent::__construct(ColourSpaceFamily::Pattern, $underlying?->ranges(), ...($underlying === null ? [] : [$underlying->value()]));
    }
}
