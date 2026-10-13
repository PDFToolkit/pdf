<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Syntax\FileStructure\BasicType;

/** Explicit OCG/OCMD attachment usable in Properties, XObjects and annotations. */
interface OptionalContentObject extends BasicType
{
    public function groups(): GroupCollection;
    public function visible(GroupStates $states): bool;
}
