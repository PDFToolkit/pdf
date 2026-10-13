<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Table 127 Metadata: an explicitly supplied XML metadata stream (14.3.2). */
final readonly class FontProgramMetadata
{
    public function __construct(public StreamObject $stream)
    {
        $type = $stream->dictionary()->get('Type');
        $subtype = $stream->dictionary()->get('Subtype');
        if (!$type instanceof NameType || $type->value !== 'Metadata' || !$subtype instanceof NameType || $subtype->value !== 'XML') {
            throw new \InvalidArgumentException('Font program Metadata is an XML metadata stream.');
        }
    }
}
