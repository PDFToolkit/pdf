<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\NameType;

/** An additional standard hint table and its explicit stream dictionary key. */
final readonly class HintTableEntry
{
    public function __construct(public NameType $name, public GenericHintTable|ExtendedGenericHintTable|ThumbnailHintTable|EmbeddedFileHintTable $table)
    {
        if (!in_array($name->value, ['O', 'A', 'E', 'V', 'I', 'C', 'L', 'R', 'T', 'B'], true)) {
            throw new \InvalidArgumentException('The hint key must name a generic or extended generic table in Table F.2.');
        }
        if (in_array($name->value, ['V', 'C', 'R'], true) !== ($table instanceof ExtendedGenericHintTable)) {
            throw new \InvalidArgumentException('Form, logical structure and rendition hints require the extended generic format.');
        }
        if (($name->value === 'T') !== ($table instanceof ThumbnailHintTable) || ($name->value === 'B') !== ($table instanceof EmbeddedFileHintTable)) {
            throw new \InvalidArgumentException('Thumbnail and embedded-file hints use their specific table formats.');
        }
    }
}
