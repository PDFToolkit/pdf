<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;

/** Ordered /C class associations, retaining element-local revisions. */
final readonly class AttributeClassAssociations
{
    /** @var list<AttributeClassAssociation> */
    public array $associations;

    public function __construct(AttributeClassAssociation ...$associations)
    {
        if ($associations === []) {
            throw new \InvalidArgumentException('An explicit /C requires at least one class name.');
        }
        $this->associations = array_values($associations);
    }

    public function value(): BasicType
    {
        if (count($this->associations) === 1 && $this->associations[0]->revision->integer() === 0) {
            return $this->associations[0]->name;
        }
        $values = [];
        foreach ($this->associations as $association) {
            $values[] = $association->name;
            if ($association->revision->integer() !== 0) {
                $values[] = $association->revision;
            }
        }
        return new ArrayType(...$values);
    }
}
