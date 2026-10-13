<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;

/** Delete exactly one currently live generation (7.5.4/7.5.6), retaining its original physical bytes. */
final readonly class ReleaseIndirectObject
{
    public function __construct(public ObjectIdentifier $identifier)
    {
    }
}
