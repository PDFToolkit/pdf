<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\StringType;

final readonly class EmbeddedFileAttachment
{
    public function __construct(public StringType $key, public FileSpecification $file)
    {
        if (!$file->has('EF')) {
            throw new \InvalidArgumentException('An EmbeddedFiles entry must reference a file specification containing EF.');
        }
    }
}
