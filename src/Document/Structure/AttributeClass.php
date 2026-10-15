<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NameType;

/** A class shares attribute objects; it has no class inheritance (PDF page 575). */
final readonly class AttributeClass
{
    /** @var list<StructureAttributeObject> */
    public array $attributes;

    public function __construct(public NameType $name, StructureAttributeObject ...$attributes)
    {
        $this->attributes = array_values($attributes);
    }

    public function value(): BasicType
    {
        $values = array_map(static fn (StructureAttributeObject $attribute): BasicType => $attribute->value(), $this->attributes);
        return count($values) === 1 ? $values[0] : new ArrayType(...$values);
    }
}
