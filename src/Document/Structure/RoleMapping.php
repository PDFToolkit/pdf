<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\Object\NameType;

/** One association in the document's role map (14.7.3, PDF page 567). */
final readonly class RoleMapping
{
    public NameType $to;

    public function __construct(public NameType $from, NameType|StandardStructureType $to)
    {
        $this->to = $to instanceof StandardStructureType ? $to->name() : $to;
    }
}
