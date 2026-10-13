<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** One class name and its element-local revision (PDF pages 575–576). */
final readonly class AttributeClassAssociation
{
    public function __construct(public NameType $name, public IntegerType $revision = new IntegerType(0))
    {
        if ($revision->integer() < 0) {
            throw new \InvalidArgumentException('An attribute class revision must be non-negative.');
        }
    }

    public function currentFor(StructureElement $element): bool
    {
        return $this->revision->integer() === $element->revision->integer();
    }
}
