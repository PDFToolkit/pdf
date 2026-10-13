<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NullType;

/** Choose which of the specification's inconsistent V formulations the stored strings follow. */
final readonly class ChoiceFieldValue
{
    public function __construct(public ChoiceValueDomain $domain, public TextString|ArrayType|NullType $object)
    {
        foreach ($object instanceof ArrayType ? $object->items : ($object instanceof NullType ? [] : [$object]) as $item) {
            if (!$item instanceof TextString) {
                throw new \InvalidArgumentException('Choice values are explicit text strings.');
            }
        }
    }
}
