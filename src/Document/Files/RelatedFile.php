<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\StringType;

final readonly class RelatedFile
{
    public function __construct(public StringType $name, public EmbeddedFileStream $stream)
    {
    }
}
