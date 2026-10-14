<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\NameType;

/** Table43/p.152 permits standard values and developer-defined relationship names. */
final readonly class AssociatedFileRelationship
{
    public NameType $name;

    public function __construct(AFRelationship|NameType $relationship)
    {
        $this->name = $relationship instanceof AFRelationship ? new NameType($relationship->value) : $relationship;
        if ($this->name->value === '') {
            throw new \InvalidArgumentException('An associated-file relationship requires a nonempty PDF name.');
        }
    }
}
