<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;

/** Ordered A associations with the optional revision-number encoding of 14.7.5.3. */
final readonly class StructureAttributes
{
    /** @var list<AttributeAssociation> */
    public array $associations;

    public function __construct(AttributeAssociation ...$associations)
    {
        if ($associations === []) {
            throw new \InvalidArgumentException('An explicit /A requires at least one attribute object.');
        }
        $this->associations = array_values($associations);
    }

    public function value(): BasicType
    {
        if (count($this->associations) === 1 && $this->associations[0]->revision->integer() === 0) {
            return $this->associations[0]->attribute->value();
        }
        $values = [];
        foreach ($this->associations as $association) {
            $values[] = $association->attribute->value();
            if ($association->revision->integer() !== 0) {
                $values[] = $association->revision;
            }
        }
        return new ArrayType(...$values);
    }
}
