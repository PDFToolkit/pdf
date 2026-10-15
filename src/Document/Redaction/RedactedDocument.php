<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\FileStructure;

/** Only fresh-file output: never an incremental update containing recoverable old bytes. */
final readonly class RedactedDocument implements \Stringable
{
    public function __construct(private FileStructure $file, public int $removedPaintObjects, public int $removedSharedResources)
    {
    }

    public function toPdf(): string
    {
        return $this->file->toPdf();
    }

    public function writeTo(string $path): void
    {
        $this->file->writeTo($path);
    }

    public function __toString(): string
    {
        return $this->toPdf();
    }
}
