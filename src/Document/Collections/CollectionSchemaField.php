<?php

declare(strict_types=1);

namespace PDF\Document\Collections;

use PDF\Syntax\Object\NameType;

final readonly class CollectionSchemaField
{
    public function __construct(public NameType $name, public CollectionField $field)
    {
        if ($name->value === 'Type') {
            throw new \InvalidArgumentException('A collection-schema field cannot replace the Type entry.');
        }
    }
}
