<?php

declare(strict_types=1);

namespace PDF\Document\Information;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

final class Trapped extends Entry
{
    public function __construct(NameType $value)
    {
        if (!in_array($value->value, ['True', 'False', 'Unknown'], true)) {
            throw new \InvalidArgumentException('Trapped must be /True, /False or /Unknown.');
        }
        parent::__construct(new NameType('Trapped'), $value);
    }
}
