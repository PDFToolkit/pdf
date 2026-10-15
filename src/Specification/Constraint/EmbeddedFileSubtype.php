<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Document\Files\EmbeddedFileMediaType;
use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Table44 p.153 / EC3 #155; presence for associated files is a graph condition. */
final readonly class EmbeddedFileSubtype implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $value = (new DictionaryValues())->value($dictionary, 'Subtype');
        if ($value === null) {
            return;
        }
        if (!$value instanceof NameType) {
            throw new \InvalidArgumentException('An embedded-file /Subtype requires a name.');
        }
        new EmbeddedFileMediaType($value);
    }
}
