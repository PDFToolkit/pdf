<?php

declare(strict_types=1);

namespace PDF\Document\Files;

final readonly class RelatedFileBinding
{
    public function __construct(public EmbeddedFileKey $key, public RelatedFiles $files)
    {
    }
}
