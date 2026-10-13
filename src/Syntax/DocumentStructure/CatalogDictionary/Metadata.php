<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure\CatalogDictionary;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

final class Metadata extends Entry
{
    public function __construct(StreamObject|IndirectReference $value)
    {
        if ($value instanceof IndirectReference && !$value->target() instanceof StreamObject) {
            throw new \InvalidArgumentException('Metadata must reference a stream.');
        }
        parent::__construct(new NameType('Metadata'), $value instanceof StreamObject ? new IndirectReference($value) : $value);
    }
}
