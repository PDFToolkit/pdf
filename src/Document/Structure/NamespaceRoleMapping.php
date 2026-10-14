<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Table 356: a bare target name means the default standard namespace, not the source namespace. */
final readonly class NamespaceRoleMapping
{
    public function __construct(public NameType $from, public StructureRole $to)
    {
    }

    public function entry(): Entry
    {
        return new Entry($this->from, $this->to->namespace === null ? $this->to->type : new ArrayType($this->to->type, new IndirectReference($this->to->namespace)));
    }
}
