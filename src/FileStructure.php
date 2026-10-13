<?php

declare(strict_types=1);

namespace PDF;

use PDF\Serialization\FileWriter;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** The file's header, body, root and trailer additions (7.5). */
final readonly class FileStructure implements \Stringable
{
    public function __construct(
        public FileHeader $header,
        public FileBody $body,
        public ?IndirectReference $root = null,
        public ?DictionaryType $trailerEntries = null,
        public CrossReferenceFormat $crossReferenceFormat = CrossReferenceFormat::Table,
    ) {
    }

    public function toPdf(): string
    {
        return (new FileWriter())->serialize($this);
    }

    /** Writes to a filesystem path chosen by the application; stream URIs are refused. */
    public function writeTo(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1) {
            throw new \InvalidArgumentException('The PDF output must be a non-empty filesystem path without a stream wrapper.');
        }
        $bytes = $this->toPdf();
        $written = @file_put_contents($path, $bytes, LOCK_EX);
        if ($written !== strlen($bytes)) {
            throw new \RuntimeException('Unable to write the complete PDF.');
        }
    }

    public function __toString(): string
    {
        return $this->toPdf();
    }
}
