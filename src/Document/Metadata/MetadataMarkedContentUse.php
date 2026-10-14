<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

use PDF\Syntax\Object\NameType;

/** The exact BDC/DP property operand frozen with native content-stream emission. */
final readonly class MetadataMarkedContentUse
{
    public function __construct(public string $symbol, public NameType $properties)
    {
    }
}
