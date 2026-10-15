<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** EC3 #290/#612, pp.851–852: flat child references and no XMP metadata on DPart nodes. */
final readonly class DocumentPartEntries implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        if ($values->value($dictionary, 'Metadata') !== null) {
            throw new \InvalidArgumentException('EC3 #290 forbids /Metadata in a DPart dictionary.');
        }
        $children = $values->value($dictionary, 'DParts');
        if ($children === null) {
            return;
        }
        if (!$children instanceof ArrayType || count($children) === 0) {
            throw new \InvalidArgumentException('EC3 #612 /DParts requires a nonempty flat array of indirect child references.');
        }
        if ($values->value($dictionary, 'Start') !== null || $values->value($dictionary, 'End') !== null) {
            throw new \InvalidArgumentException('A DPart branch cannot also carry /Start or /End.');
        }
        foreach ($children as $child) {
            if (!$child instanceof IndirectReference || !$child->target() instanceof DictionaryType) {
                throw new \InvalidArgumentException('EC3 #612 /DParts requires a flat array of indirect dictionary references.');
            }
        }
    }
}
