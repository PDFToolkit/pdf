<?php

declare(strict_types=1);

namespace PDF\Document\Files;

final readonly class EmbeddedFileBinding
{
    public function __construct(public EmbeddedFileKey $key, public EmbeddedFileStream $stream)
    {
    }
}
