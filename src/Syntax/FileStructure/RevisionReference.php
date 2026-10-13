<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectReference;

/** The identifier actually emitted for this exact construction-reference instance. */
final readonly class RevisionReference
{
    public function __construct(public IndirectReference $reference, public ObjectIdentifier $identifier)
    {
    }
}
