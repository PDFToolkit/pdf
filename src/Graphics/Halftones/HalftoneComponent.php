<?php

declare(strict_types=1);

namespace PDF\Graphics\Halftones;

use PDF\Syntax\Object\NameType;

final readonly class HalftoneComponent
{
    public function __construct(public NameType $colorant, public HalftoneObject $halftone)
    {
        if ($halftone->halftoneType() === 5 || in_array($colorant->value, ['Type', 'HalftoneType', 'HalftoneName', 'Default'], true)) {
            throw new \InvalidArgumentException('A type5 component needs a nonreserved colorant and a non-type5 halftone.');
        }
        if (!in_array($colorant->value, ['Gray', 'Red', 'Green', 'Blue', 'Cyan', 'Magenta', 'Yellow', 'Black'], true) && !$halftone->hasTransferFunction()) {
            throw new \InvalidArgumentException('A nonprimary/nonstandard colorant halftone requires its own transfer function.');
        }
    }

    public function nonprimary(): bool
    {
        return !in_array($this->colorant->value, ['Gray', 'Red', 'Green', 'Blue', 'Cyan', 'Magenta', 'Yellow', 'Black'], true);
    }
}
