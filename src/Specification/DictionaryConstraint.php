<?php

declare(strict_types=1);

namespace PDF\Specification;

use PDF\Syntax\Object\DictionaryType;

/** A reviewed relation between values in one effective dictionary. */
interface DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void;
}
