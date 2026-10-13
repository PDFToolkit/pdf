<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\IntegerType;

/** Revision numbers belong to associations, never the shared attribute object (PDF page 576). */
final readonly class AttributeAssociation
{
    public function __construct(public StructureAttributeObject $attribute, public IntegerType $revision = new IntegerType(0))
    {
        if ($revision->integer() < 0) {
            throw new \InvalidArgumentException('An attribute revision must be non-negative.');
        }
    }

    public function currentFor(StructureElement $element): bool
    {
        return $this->revision->integer() === $element->revision->integer();
    }
}
